<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Service;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cerebro conversacional de la clínica: arma el system prompt (persona +
 * cumplimiento sanitario + base de conocimiento RAG) y responde con Claude
 * conservando la memoria de la conversación.
 */
class BotService
{
    public function __construct(
        private readonly User $user,
        private readonly AnthropicService $ai,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self($user, AnthropicService::fromConfig());
    }

    public function isReady(): bool
    {
        return $this->ai->isConfigured();
    }

    /**
     * Genera la respuesta del bot para el último mensaje de la conversación.
     *
     * Devuelve el texto ya limpio y la lista de fotos/videos que el bot decidió
     * enviar (resueltos a URLs públicas listas para WhatsApp/Instagram o el panel).
     *
     * @return array{text:string, media:array<int,array{type:string,url:?string,caption:string,service:string}>}
     */
    public function reply(Conversation $conversation, ?Campaign $campaign = null): array
    {
        $messages = $conversation->messages()
            ->orderBy('id')
            ->get(['role', 'content'])
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $system = $this->systemPrompt($campaign);
        $tools = $this->canSchedule() ? $this->bookingTools() : [];

        // Cada foto/video se envía UNA sola vez por conversación: recolectamos las URLs
        // ya enviadas antes y las filtramos abajo. Salvo que el paciente pida verlas de
        // nuevo explícitamente, en cuyo caso permitimos el reenvío.
        $alreadySent = $this->wantsMediaResend($conversation)
            ? []
            : $this->sentMediaUrls($conversation);

        // Sin agenda conectada: respuesta de texto simple (comportamiento original).
        if (empty($tools)) {
            return $this->parseMedia($this->ai->chat($system, $messages, 1024), $campaign, $alreadySent);
        }

        // Con agenda: ciclo de herramientas (el bot consulta disponibilidad y agenda).
        for ($turn = 0; $turn < 6; $turn++) {
            $response = $this->ai->rawChat($system, $messages, $tools, 1024);
            $blocks = $response['content'] ?? [];

            if (($response['stop_reason'] ?? null) === 'tool_use') {
                $messages[] = ['role' => 'assistant', 'content' => $blocks];

                $results = [];
                foreach ($blocks as $block) {
                    if (($block['type'] ?? '') === 'tool_use') {
                        $results[] = [
                            'type' => 'tool_result',
                            'tool_use_id' => $block['id'],
                            'content' => $this->runTool($block['name'], $block['input'] ?? []),
                        ];
                    }
                }
                $messages[] = ['role' => 'user', 'content' => $results];

                continue;
            }

            // Respuesta final de texto.
            $text = collect($blocks)->where('type', 'text')->pluck('text')->implode("\n");

            return $this->parseMedia($text, $campaign, $alreadySent);
        }

        return $this->parseMedia('Disculpa, tuve un inconveniente al procesar tu solicitud. ¿Lo intentamos de nuevo?', $campaign, $alreadySent);
    }

    /**
     * ¿El bot puede agendar? (IA lista + Google Calendar conectado.)
     */
    public function canSchedule(): bool
    {
        return $this->isReady() && Settings::hasGoogleCalendar();
    }

    /**
     * URLs de fotos/videos que YA se enviaron antes en esta conversación, para no
     * reenviar la misma pieza (se manda una sola vez por conversación).
     *
     * @return array<int, string>
     */
    private function sentMediaUrls(Conversation $conversation): array
    {
        return $conversation->messages()
            ->where('role', 'assistant')
            ->whereNotNull('media')
            ->get(['media'])
            ->flatMap(fn ($message) => collect($message->media ?? [])->pluck('url'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ¿El último mensaje del paciente pide ver la media de nuevo? En ese caso permitimos
     * reenviarla (se salta el filtro de "una vez por conversación").
     */
    private function wantsMediaResend(Conversation $conversation): bool
    {
        $last = $conversation->messages()
            ->where('role', 'user')
            ->orderByDesc('id')
            ->value('content');

        if (blank($last)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(otra vez|de nuevo|nuevamente|rep[ií]t\w*|vuelv\w+ a (enviar|mandar|mostrar|pasar)|'
            .'(m[aá]nd\w*|env[ií]\w*|mu[eé]str\w*|pas\w*|comp[aá]rt\w*)[^.?!]*\b(foto|fotos|video|videos|imagen|im[aá]genes)\b)/iu',
            (string) $last,
        );
    }

    /**
     * Extrae las etiquetas [[media:slug]] que el bot insertó, las resuelve a
     * fotos/videos reales del servicio y las quita del texto visible.
     *
     * @return array{text:string, media:array<int,array{type:string,url:?string,caption:string,service:string}>}
     */
    private function parseMedia(string $raw, ?Campaign $campaign = null, array $alreadySent = []): array
    {
        $media = [];
        $seen = [];

        $text = preg_replace_callback(
            '/\[\[\s*media\s*:\s*([a-z0-9\-]+)\s*\]\]/i',
            function (array $m) use (&$media, &$seen, $campaign, $alreadySent) {
                $slug = strtolower($m[1]);
                if (in_array($slug, $seen, true)) {
                    return '';
                }
                $seen[] = $slug;

                // Palabra reservada: material visual de la campaña/anuncio de origen.
                if (in_array($slug, ['anuncio', 'campana', 'campania'], true)) {
                    if ($campaign) {
                        foreach ($campaign->media as $item) {
                            if (blank($item->resolved_url) || in_array($item->resolved_url, $alreadySent, true)) {
                                continue;
                            }
                            $media[] = [
                                'type' => $item->type,
                                'url' => $item->resolved_url,
                                'caption' => $item->caption ?: $campaign->name,
                                'service' => $campaign->name,
                            ];
                        }
                    }

                    return '';
                }

                $service = $this->user->services()
                    ->where('is_active', true)
                    ->where('slug', $slug)
                    ->first();

                if ($service) {
                    foreach ($service->media as $item) {
                        if (blank($item->resolved_url) || in_array($item->resolved_url, $alreadySent, true)) {
                            continue;
                        }
                        $media[] = [
                            'type' => $item->type,
                            'url' => $item->resolved_url,
                            'caption' => $item->caption ?: $service->name,
                            'service' => $service->name,
                        ];
                    }
                }

                return '';
            },
            $raw,
        );

        return [
            'text' => self::stripMarkdown($text),
            'media' => $media,
        ];
    }

    /**
     * Quita el formato Markdown que el modelo pueda colar (negritas, viñetas,
     * encabezados, enlaces…) para que las respuestas se vean limpias en
     * canales de texto plano como WhatsApp o Instagram.
     */
    public static function stripMarkdown(string $text): string
    {
        // Negritas/itálicas: **x**, __x__, *x*, _x_  ->  x
        $text = preg_replace('/(\*\*|__)(.+?)\1/s', '$2', $text);
        $text = preg_replace('/(?<!\*)\*(?!\s)([^*\n]+?)(?<!\s)\*(?!\*)/', '$1', $text);
        $text = preg_replace('/(?<![A-Za-z0-9_])_(?!\s)([^_\n]+?)(?<!\s)_(?![A-Za-z0-9_])/', '$1', $text);

        // Código en línea: `x` -> x
        $text = preg_replace('/`([^`\n]+)`/', '$1', $text);

        // Enlaces e imágenes: [texto](url) -> texto, ![alt](url) -> alt
        $text = preg_replace('/!?\[([^\]]+)\]\([^)]*\)/', '$1', $text);

        // Encabezados al inicio de línea: ###  -> (nada)
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text);

        // Viñetas con * o - al inicio de línea -> •
        $text = preg_replace('/^(\s*)[\*\-]\s+/m', '$1• ', $text);

        return trim($text);
    }

    /**
     * Herramientas que el bot puede usar para agendar (Anthropic tool use).
     *
     * @return array<int,array<string,mixed>>
     */
    private function bookingTools(): array
    {
        return [
            [
                'name' => 'consultar_disponibilidad',
                'description' => 'Devuelve los horarios LIBRES reales de la clínica (fechas y horas exactas), ya descontando lo ocupado en la agenda y respetando el horario de atención. Úsala ANTES de proponer horarios y ofrécele al paciente las fechas y horas concretas que devuelva, nunca términos vagos como "mañana". Puedes revisar varios días a la vez con el parámetro "dias".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'fecha' => ['type' => 'string', 'description' => 'Fecha de inicio a consultar en formato YYYY-MM-DD. Si se omite, se usa hoy.'],
                        'dias' => ['type' => 'integer', 'description' => 'Cuántos días consecutivos revisar a partir de la fecha (1 a 7). Por defecto 3. Usa más si el paciente pregunta "esta semana" o no tiene un día fijo.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'agendar_cita',
                'description' => 'Registra una cita en la agenda de la clínica (queda en Google Calendar). Úsala SOLO cuando el paciente haya confirmado un día y hora concretos y tengas su nombre. Verifica antes con consultar_disponibilidad.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'nombre_paciente' => ['type' => 'string', 'description' => 'Nombre del paciente.'],
                        'telefono' => ['type' => 'string', 'description' => 'Teléfono de contacto, si lo tienes.'],
                        'correo' => ['type' => 'string', 'description' => 'Correo, si lo tienes.'],
                        'servicio' => ['type' => 'string', 'description' => 'Nombre del servicio o motivo de la cita, si aplica.'],
                        'fecha_hora' => ['type' => 'string', 'description' => 'Fecha y hora de inicio en formato YYYY-MM-DDTHH:MM (hora local de la clínica).'],
                        'duracion_minutos' => ['type' => 'integer', 'description' => 'Duración en minutos. Si se omite, se usa la del servicio o 45.'],
                        'notas' => ['type' => 'string', 'description' => 'Notas u observaciones.'],
                    ],
                    'required' => ['nombre_paciente', 'fecha_hora'],
                ],
            ],
        ];
    }

    /**
     * Ejecuta una herramienta y devuelve el texto resultado para el modelo.
     *
     * @param  array<string,mixed>  $input
     */
    private function runTool(string $name, array $input): string
    {
        try {
            return match ($name) {
                'consultar_disponibilidad' => $this->toolAvailability($input),
                'agendar_cita' => $this->toolBook($input),
                default => 'Herramienta desconocida.',
            };
        } catch (Throwable $e) {
            return 'ERROR al ejecutar la herramienta: '.$e->getMessage();
        }
    }

    /**
     * Calcula y devuelve los horarios LIBRES reales (fechas + horas exactas) en un
     * rango de días: parte del horario de atención y le descuenta lo ocupado en
     * Google Calendar y lo que ya pasó hoy.
     *
     * @param  array<string,mixed>  $input
     */
    private function toolAvailability(array $input): string
    {
        $tz = Settings::googleTimezone();
        $now = Carbon::now($tz);
        $today = $now->copy()->startOfDay();

        $start = filled($input['fecha'] ?? null)
            ? Carbon::parse($input['fecha'], $tz)->startOfDay()
            : $today->copy();
        if ($start->lt($today)) {
            $start = $today->copy();
        }

        $days = (int) ($input['dias'] ?? 3);
        $days = max(1, min($days, 7));

        $hours = Settings::scheduleHours();
        $slotMin = max(15, Settings::scheduleSlotMinutes());

        // Una sola llamada a freeBusy para todo el rango.
        $rangeEnd = $start->copy()->addDays($days - 1)->endOfDay();
        $busyRaw = GoogleCalendarService::fromConfig()->busyTimes(
            $start->copy()->toRfc3339String(),
            $rangeEnd->toRfc3339String(),
        );
        $busy = array_map(fn (array $b) => [
            'start' => Carbon::parse($b['start'])->tz($tz),
            'end' => Carbon::parse($b['end'])->tz($tz),
        ], $busyRaw);

        $lines = [];
        $anyFree = false;

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $window = $hours[$day->dayOfWeek] ?? null;

            $label = ucfirst($day->locale('es')->isoFormat('dddd D [de] MMMM'));

            if (! $window) {
                $lines[] = "{$label}: cerrado.";

                continue;
            }

            $open = $day->copy()->setTimeFromTimeString($window[0]);
            $close = $day->copy()->setTimeFromTimeString($window[1]);

            $slots = [];
            for ($t = $open->copy(); $t->copy()->addMinutes($slotMin)->lte($close); $t->addMinutes($slotMin)) {
                $slotStart = $t->copy();
                $slotEnd = $t->copy()->addMinutes($slotMin);

                if ($slotStart->lte($now)) {
                    continue; // ya pasó
                }

                $occupied = false;
                foreach ($busy as $b) {
                    if ($slotStart->lt($b['end']) && $slotEnd->gt($b['start'])) {
                        $occupied = true;
                        break;
                    }
                }

                if (! $occupied) {
                    $slots[] = $slotStart->locale('es')->isoFormat('h:mm a');
                }
            }

            if (empty($slots)) {
                $lines[] = "{$label}: sin horarios disponibles.";

                continue;
            }

            $anyFree = true;
            $shown = array_slice($slots, 0, 10);
            $more = count($slots) - count($shown);
            $line = "{$label}: ".implode(', ', $shown);
            if ($more > 0) {
                $line .= " (y {$more} más)";
            }
            $lines[] = $line;
        }

        if (! $anyFree) {
            return 'No encontré horarios libres en los días revisados a partir del '
                .$start->locale('es')->isoFormat('D [de] MMMM').'. Sugiérele al paciente revisar otra fecha más adelante.';
        }

        return "Horarios DISPONIBLES (zona horaria {$tz}). Ofrécele al paciente estas fechas y horas EXACTAS, nunca términos vagos:\n"
            .implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function toolBook(array $input): string
    {
        $tz = Settings::googleTimezone();
        $start = Carbon::parse($input['fecha_hora'], $tz);

        // Resuelve el servicio a partir del texto del paciente (admite nombres
        // comerciales como "botox" aunque el catálogo use el nombre clínico).
        $requested = trim((string) ($input['servicio'] ?? ''));
        $service = $this->resolveService($requested);

        $duration = (int) ($input['duracion_minutos'] ?? $service?->duration_minutes ?: 45);
        $end = $start->copy()->addMinutes($duration);

        // Re-verifica que no se solape con algo ya ocupado.
        $busy = GoogleCalendarService::fromConfig()->busyTimes(
            $start->copy()->startOfDay()->toRfc3339String(),
            $start->copy()->endOfDay()->toRfc3339String(),
        );
        foreach ($busy as $b) {
            $bs = Carbon::parse($b['start']);
            $be = Carbon::parse($b['end']);
            if ($start->lt($be) && $end->gt($bs)) {
                return "ERROR: el horario de las {$start->format('h:i a')} ya está ocupado. No agendes ahí; ofrece otro horario libre.";
            }
        }

        // Vincula con un lead existente por teléfono, si lo hay.
        $lead = null;
        if (filled($input['telefono'] ?? null)) {
            $digits = preg_replace('/\D/', '', (string) $input['telefono']);
            if (strlen($digits) >= 7) {
                $lead = $this->user->leads()->where('phone', 'like', '%'.$digits.'%')->first();
            }
        }

        // Si no se logró vincular un servicio del catálogo, conserva igual el
        // tratamiento que pidió el paciente en las notas (así no se pierde y
        // aparece en la descripción del evento de Google).
        $notes = trim((string) ($input['notas'] ?? ''));
        if (! $service && $requested !== '') {
            $notes = trim(($notes !== '' ? $notes."\n" : '')."Tratamiento solicitado: {$requested}");
        }

        $appointment = $this->user->appointments()->create([
            'lead_id' => $lead?->id,
            'service_id' => $service?->id,
            'patient_name' => $input['nombre_paciente'],
            'patient_phone' => $input['telefono'] ?? $lead?->phone,
            'patient_email' => $input['correo'] ?? $lead?->email,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $end->format('Y-m-d H:i:s'),
            'status' => 'scheduled',
            'notes' => $notes !== '' ? $notes : null,
        ]);

        try {
            $google = GoogleCalendarService::fromConfig();
            $appointment->google_event_id = $google->createEvent($appointment);
            $appointment->google_synced_at = now();
            $appointment->save();
        } catch (Throwable $e) {
            $appointment->forceFill(['google_sync_error' => $e->getMessage()])->save();

            return 'La cita quedó guardada pero no se pudo poner en el calendario: '.$e->getMessage()
                .' Avísale al paciente que la confirmarás en breve.';
        }

        $when = $start->format('Y-m-d').' a las '.$start->format('h:i a');

        return 'Cita agendada con éxito para '.$input['nombre_paciente'].' el '.$when
            .($service ? ' ('.$service->name.')' : '')
            .'. Quedó registrada en la agenda. Confírmasela al paciente con calidez y recuérdale la dirección de la clínica.';
    }

    /**
     * Encuentra el servicio del catálogo que mejor corresponde al texto que dio
     * el paciente. Es tolerante: admite frases ("quiero botox para la frente"),
     * nombres comerciales y busca también en la descripción/contexto del servicio.
     */
    private function resolveService(?string $query): ?Service
    {
        $query = trim((string) $query);
        if ($query === '') {
            return null;
        }

        $services = $this->user->services()->where('is_active', true)->get();
        if ($services->isEmpty()) {
            return null;
        }

        $q = Str::lower($query);

        // 1) Coincidencia directa: el nombre contiene la frase, o la frase el nombre.
        foreach ($services as $s) {
            $name = Str::lower($s->name);
            if (Str::contains($name, $q) || Str::contains($q, $name)) {
                return $s;
            }
        }

        // 2) Coincidencia por palabras significativas. Da más peso a las que
        //    aparecen en el nombre del servicio que a las del texto descriptivo.
        $stop = ['para', 'con', 'los', 'las', 'del', 'una', 'uno', 'que', 'tratamiento',
            'tratamientos', 'cita', 'quiero', 'sesion', 'sesión', 'servicio', 'por', 'mis',
            'una', 'algo', 'sobre', 'zona', 'aplicar', 'aplicacion', 'aplicación'];

        $words = collect(preg_split('/\s+/', $q))
            ->map(fn ($w) => preg_replace('/[^a-záéíóúñ0-9]/u', '', (string) $w))
            ->filter(fn ($w) => strlen((string) $w) >= 4 && ! in_array($w, $stop, true))
            ->unique()
            ->values();

        if ($words->isEmpty()) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($services as $s) {
            $name = Str::lower($s->name);
            $haystack = Str::lower($s->name.' '.$s->short_description.' '.$s->ai_context);

            $nameHits = $words->filter(fn ($w) => Str::contains($name, $w))->count();
            $textHits = $words->filter(fn ($w) => Str::contains($haystack, $w))->count();
            $score = $nameHits * 2 + $textHits;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $s;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    /**
     * Construye el system prompt con persona, reglas y base de conocimiento.
     */
    public function systemPrompt(?Campaign $campaign = null): string
    {
        $c = Settings::botConfig();

        $persona = trim((string) $c['bot_persona']);
        $personaBlock = $persona !== '' ? "\n\nIndicaciones adicionales de la doctora:\n{$persona}" : '';

        $schedulingBlock = $this->canSchedule() ? $this->schedulingPrompt() : '';
        $campaignBlock = $campaign ? $this->campaignPrompt($campaign) : '';

        return <<<PROMPT
        Eres el asistente virtual de {$c['clinic_name']}, un consultorio de medicina estética premium dirigido por la Dra. Jasmin Blanco. Atiendes a pacientes por WhatsApp e Instagram con calidez y profesionalismo, como lo haría una asesora humana experimentada.
        {$campaignBlock}
        # Tu objetivo
        Resolver dudas frecuentes, construir valor y, cuando el paciente muestre interés real, motivar y ayudar a agendar una valoración. Nunca presionas; acompañas.

        # Tono y estilo
        - Español natural, cálido y cercano, pero profesional. Trato de "tú".
        - Mensajes breves y claros, como en un chat real. Evita textos enormes.
        - Usa el nombre del paciente si lo conoces. Muestra empatía genuina.

        # Formato de los mensajes
        - Escribe en TEXTO PLANO, como un mensaje real de WhatsApp. WhatsApp e Instagram NO renderizan Markdown.
        - NO uses asteriscos (**), almohadillas (#), guiones bajos (_) ni ningún símbolo de Markdown para dar formato.
        - Para enumerar, usa frases cortas o viñetas con "•" o emojis discretos, no listas con "*" ni "-".
        - Para resaltar algo, usa MAYÚSCULAS con moderación o simplemente el contexto; nunca negritas con asteriscos.

        # Información de la clínica
        - Nombre: {$c['clinic_name']}
        - Dirección: {$c['clinic_address']}
        - Horarios: {$c['clinic_hours']}
        - Formas de pago: {$c['clinic_payment']}

        # Reglas importantes (cumplimiento sanitario)
        - NO diagnosticas ni recetas. Toda recomendación requiere una valoración médica presencial con la Dra. Blanco.
        - NO prometes resultados garantizados; cada paciente es diferente.
        - Menciona contraindicaciones generales cuando sea pertinente y sugiere valoración previa.
        - Respeta la normativa (Invima, SIC) y la protección de datos (habeas data): no insistas por datos sensibles innecesarios.
        - Si no tienes la información en tu base de conocimiento, no la inventes: ofrece resolverlo en la valoración o derivar al equipo humano.

        # Escalamiento a humano
        Si el paciente lo pide explícitamente, está molesto/frustrado, o la consulta es médica específica que requiere criterio profesional, indícale con calidez que lo derivas con el equipo de la doctora y resume brevemente lo conversado.

        {$schedulingBlock}
        # Material visual (fotos y videos)
        Algunos servicios tienen fotos o videos disponibles; en la base de conocimiento aparecen marcados como "Material visual disponible" con su identificador.
        - Cuando el paciente pida ver fotos, videos, resultados o "antes y después", o cuando mostrar el material ayude a generar confianza y el servicio lo tenga, ENVÍALO.
        - Para enviarlo, escribe la etiqueta [[media:identificador]] en una línea aparte (por ejemplo [[media:limpieza-facial-profunda]]). El sistema la reemplaza automáticamente por las fotos/videos reales; el paciente NO ve la etiqueta.
        - Acompaña la etiqueta con una frase cálida y natural ("Te comparto unas imágenes para que veas el resultado 😊").
        - Solo usa identificadores que existan en la base de conocimiento. Si un servicio no tiene material visual, no inventes la etiqueta: ofrece resolverlo en la valoración.
        - Envía cada foto o video UNA sola vez por conversación. Si ya lo compartiste antes, NO lo reenvíes ni escribas que lo adjuntas de nuevo (puedes referirte a él con palabras). Solo reenvíalo si el paciente pide expresamente verlo otra vez.

        # Base de conocimiento de la clínica
        Responde ÚNICAMENTE con base en la siguiente información:

        {$this->knowledgeBase()}
        {$personaBlock}
        PROMPT;
    }

    /**
     * Contexto de la campaña de Meta de la que viene el paciente, para que el bot
     * responda enfocado en el servicio y la oferta de ese anuncio.
     */
    private function campaignPrompt(Campaign $campaign): string
    {
        $campaign->loadMissing('service', 'media');

        $lines = ['# Contexto de la campaña de origen'];
        $lines[] = "Este paciente llegó por la campaña \"{$campaign->name}\" de Meta. Atiéndelo dando por hecho que viene interesado por ese anuncio.";

        if ($campaign->service) {
            $lines[] = "Servicio promocionado en el anuncio: {$campaign->service->name}. Prioriza este servicio en tu respuesta.";
        }

        if (filled($campaign->offer)) {
            $lines[] = "Oferta / ángulo del anuncio (aprovéchalo con naturalidad, sin sonar a vendedor):\n{$campaign->offer}";
        }

        $usableMedia = $campaign->media->filter(fn ($m) => filled($m->resolved_url));
        if ($usableMedia->isNotEmpty()) {
            $photos = $usableMedia->where('type', 'image')->count();
            $videos = $usableMedia->where('type', 'video')->count();
            $bits = [];
            if ($photos) {
                $bits[] = $photos.' foto'.($photos > 1 ? 's' : '');
            }
            if ($videos) {
                $bits[] = $videos.' video'.($videos > 1 ? 's' : '');
            }
            $lines[] = 'Este anuncio tiene material visual propio ('.implode(' y ', $bits)
                .'). Para enviárselo al paciente, escribe la etiqueta [[media:anuncio]] en una línea aparte, acompañada de una frase cálida y natural. Envíalo cuando ayude a generar confianza o cuando el paciente pida ver fotos, videos o resultados. El paciente no ve la etiqueta.';
        }

        $lines[] = 'Saluda cálidamente reconociendo su interés, resuelve sus dudas sobre ese servicio y guíalo a agendar una valoración. Si pregunta por otra cosa, ayúdalo igual.';

        return "\n".implode("\n", $lines)."\n";
    }

    /**
     * Instrucciones de agendamiento + fecha de hoy (solo si la agenda está conectada).
     */
    private function schedulingPrompt(): string
    {
        $tz = Settings::googleTimezone();
        $hoy = Carbon::now($tz)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');

        return <<<PROMPT
        # Agendamiento de citas (tienes la agenda conectada)
        Hoy es {$hoy} (zona horaria {$tz}). Puedes agendar citas tú mismo.
        - Cuando el paciente quiera agendar, pide de forma natural lo que falte: su nombre, el servicio o motivo, y el día y la hora que prefiere. Pide el teléfono si aún no lo tienes.
        - Interpreta expresiones como "mañana", "el viernes" o "la próxima semana" según la fecha de hoy.
        - Para sugerir horarios, usa consultar_disponibilidad (devuelve los huecos LIBRES reales con fecha y hora exactas). Ofrécele al paciente esas fechas y horas concretas (por ejemplo "tengo disponible el martes 24 a las 9:00 a. m., 10:30 a. m. o 3:00 p. m."), NUNCA términos vagos como "mañana" o "más tarde".
        - Si el paciente no dio un día fijo, consulta varios días a la vez (parámetro "dias") y ofrécele las primeras opciones de los próximos días.
        - No ofrezcas un horario que no aparezca en la lista de disponibles, y respeta siempre el horario de atención.
        - Cuando el paciente confirme un día y una hora concretos, usa la herramienta agendar_cita. No afirmes que quedó agendada hasta que la herramienta lo confirme.
        - SIEMPRE incluye en el parámetro "servicio" el tratamiento que mencionó el paciente (por ejemplo "botox", "limpieza facial", "ácido hialurónico"), aunque use un nombre comercial; el sistema lo asocia con el servicio del catálogo. Sin esto, la cita queda sin tratamiento.
        - Después de agendar, confírmale con calidez el día y la hora, y recuérdale la dirección de la clínica.
        - Si el horario que pide ya está ocupado, discúlpate y ofrécele las opciones libres más cercanas.
        PROMPT;
    }

    /**
     * Compila servicios + entradas de conocimiento como contexto (RAG).
     */
    private function knowledgeBase(): string
    {
        $sections = [];

        $services = $this->user->services()->where('is_active', true)->with('media')->orderBy('sort_order')->get();
        if ($services->isNotEmpty()) {
            $lines = $services->map(function ($s) {
                $parts = ["## {$s->name}" . ($s->category ? " ({$s->category})" : '')];
                if (filled($s->price)) {
                    $parts[] = 'Precio referencial: $' . number_format((float) $s->price, 0, ',', '.') . ' COP';
                }
                if (filled($s->duration_minutes)) {
                    $parts[] = "Duración: {$s->duration_minutes} minutos";
                }
                $desc = $s->ai_context ?: $s->short_description;
                if (filled($desc)) {
                    $parts[] = $desc;
                }
                $usable = $s->media->filter(fn ($m) => filled($m->resolved_url));
                if ($usable->isNotEmpty()) {
                    $photos = $usable->where('type', 'image')->count();
                    $videos = $usable->where('type', 'video')->count();
                    $bits = [];
                    if ($photos) {
                        $bits[] = $photos . ' foto' . ($photos > 1 ? 's' : '');
                    }
                    if ($videos) {
                        $bits[] = $videos . ' video' . ($videos > 1 ? 's' : '');
                    }
                    $parts[] = 'Material visual disponible (' . implode(' y ', $bits)
                        . "). Para enviarlo usa la etiqueta [[media:{$s->slug}]].";
                }

                return implode("\n", $parts);
            });
            $sections[] = "### SERVICIOS\n" . $lines->implode("\n\n");
        }

        $entries = $this->user->knowledgeEntries()->where('is_active', true)->orderBy('sort_order')->get();
        if ($entries->isNotEmpty()) {
            $byCategory = $entries->groupBy('category');
            foreach ($byCategory as $category => $items) {
                $title = Str::upper(str_replace('_', ' ', (string) $category));
                $block = $items->map(fn ($e) => "## {$e->title}\n{$e->content}")->implode("\n\n");
                $sections[] = "### {$title}\n{$block}";
            }
        }

        if (empty($sections)) {
            return '(La base de conocimiento todavía está vacía. Sé honesto si no tienes la información y ofrece agendar una valoración.)';
        }

        return implode("\n\n", $sections);
    }
}
