<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\PaymentLink;
use App\Models\ReminderOptOut;
use App\Models\Service;
use App\Models\User;
use App\Support\PatientLeads;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cerebro conversacional de la clínica: arma el system prompt (persona +
 * cumplimiento sanitario + base de conocimiento RAG) y responde con Claude
 * conservando la memoria de la conversación.
 */
class BotService
{
    /**
     * Cuánto se supone que dura una cita cuando el servicio no lo dice.
     *
     * Vive aquí como constante porque la usan DOS sitios que tienen que estar
     * de acuerdo: el cálculo de horarios libres y el de agendar. Cuando no lo
     * estaban, se ofrecían huecos donde la cita no cabía.
     */
    private const DURACION_POR_DEFECTO = 45;

    /**
     * Conversación que se está atendiendo. La guardamos para que al agendar se
     * pueda vincular la cita al paciente que ya venía chateando, en vez de
     * adivinarlo por el teléfono que escriba en el mensaje.
     */
    private ?Conversation $conversation = null;

    public function __construct(
        private readonly User $user,
        private readonly AnthropicService $ai,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self($user, AnthropicService::fromConfig());
    }

    /**
     * Fija la conversación sin pasar por `reply()`.
     *
     * La necesita `payments:check-pending`, que agenda por su cuenta: sin
     * conversación, `createBooking()` no encuentra el lead que ya entró por
     * WhatsApp y crearía uno nuevo, duplicando la paciente en el pipeline.
     */
    public function forConversation(?Conversation $conversation): self
    {
        $this->conversation = $conversation;

        return $this;
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
        $this->conversation = $conversation;

        $messages = $conversation->messages()
            ->orderBy('id')
            ->get(['role', 'content'])
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $system = $this->systemPrompt($campaign);
        $tools = $this->tools();

        // Cada foto/video se envía UNA sola vez por conversación: recolectamos las URLs
        // ya enviadas antes y las filtramos abajo. Salvo que el paciente pida verlas de
        // nuevo explícitamente, en cuyo caso permitimos (y forzamos) el reenvío.
        $wantsResend = $this->wantsMediaResend($conversation);
        $alreadySent = $wantsResend ? [] : $this->sentMediaUrls($conversation);

        // Sin agenda conectada: respuesta de texto simple (comportamiento original).
        if (empty($tools)) {
            return $this->respond($this->ai->chat($system, $messages, 1024), $campaign, $alreadySent, $wantsResend, $conversation);
        }

        // Con agenda: ciclo de herramientas (el bot consulta disponibilidad y agenda).
        for ($turn = 0; $turn < 6; $turn++) {
            $response = $this->ai->rawChat($system, $messages, $tools, 1024);
            $blocks = $response['content'] ?? [];

            if (($response['stop_reason'] ?? null) === 'tool_use') {
                $messages[] = ['role' => 'assistant', 'content' => self::inputsComoObjeto($blocks)];

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

            return $this->respond($text, $campaign, $alreadySent, $wantsResend, $conversation);
        }

        return $this->respond('Disculpa, tuve un inconveniente al procesar tu solicitud. ¿Lo intentamos de nuevo?', $campaign, $alreadySent, $wantsResend, $conversation);
    }

    /**
     * Devuelve los bloques igual que llegaron, salvo el `input` de los
     * `tool_use` vacíos, que se fuerza a objeto antes de reenviarlos.
     *
     * Cuando la herramienta no lleva argumentos —`verificar_pago` es la única—
     * la API responde `"input": {}`, `json_decode` en modo array lo deja en
     * `[]`, y al reenviar el historial ese `[]` se serializa como LISTA. La API
     * rechaza entonces la llamada entera con «tool_use.input: Input should be
     * an object», el job muere y la paciente se queda sin respuesta: es un fallo
     * mudo, y encima en el peor momento, porque la frase que dispara esa
     * herramienta es «ya pagué».
     *
     * Es el mismo tropiezo que ya se corrigió en el ESQUEMA que se envía
     * (`properties => new stdClass()`), pero en el camino de vuelta, que nadie
     * miró entonces. Solo se tocan los vacíos: un input con datos ya se
     * serializa como objeto por tener claves de texto.
     */
    private static function inputsComoObjeto(array $blocks): array
    {
        return array_map(function ($block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['input'] ?? null) === []) {
                $block['input'] = new \stdClass();
            }

            return $block;
        }, $blocks);
    }

    /**
     * Convierte el texto crudo del modelo en {texto, media}. Si el paciente pidió
     * ver el material otra vez pero el modelo NO volvió a insertar la etiqueta
     * [[media:...]] (no la ve en el historial porque se guarda ya limpia),
     * reenvía de forma determinista lo último que se le mostró — así "muéstrame
     * las fotos otra vez" SIEMPRE reenvía, sin depender de que el modelo repita la etiqueta.
     *
     * @return array{text:string, media:array<int,array{type:string,url:?string,caption:string,service:string}>}
     */
    private function respond(string $raw, ?Campaign $campaign, array $alreadySent, bool $wantsResend, Conversation $conversation): array
    {
        $result = $this->parseMedia($raw, $campaign, $alreadySent);

        if ($wantsResend && empty($result['media'])) {
            $result['media'] = $this->lastSentMedia($conversation);
        }

        return $result;
    }

    /**
     * El último grupo de fotos/videos que se le envió al paciente en esta
     * conversación (para reenviarlo cuando lo pida de nuevo).
     *
     * @return array<int,array{type:string,url:?string,caption:string,service:string}>
     */
    private function lastSentMedia(Conversation $conversation): array
    {
        $media = $conversation->messages()
            ->where('role', 'assistant')
            ->whereNotNull('media')
            ->orderByDesc('id')
            ->value('media');

        return collect($media ?? [])
            ->filter(fn ($m) => filled($m['url'] ?? null))
            ->values()
            ->all();
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
     *
     * SIEMPRE hay que nombrar foto/video/imagen. Antes «otra vez», «de nuevo»,
     * «nuevamente» y «repite» eran alternativas SUELTAS, así que cualquier
     * petición de repetir algo disparaba el reenvío: «podrías enviarme los
     * horarios de nuevo» le mandaba a la paciente 3 videos y 2 fotos del
     * implante capilar. Pasó en producción el 2026-08-03.
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

        $last = (string) $last;

        // Pedir repetir, en cualquiera de sus formas.
        $repetir = '/\b(otra vez|de nuevo|nuevamente|rep[ií]t\w*|'
            .'vuelv\w+ a (enviar|mandar|mostrar|pasar)|'
            .'m[aá]nd\w*|env[ií]\w*|mu[eé]str\w*|pas\w*|comp[aá]rt\w*)/iu';

        // …referido a material visual, que es lo que faltaba exigir.
        $visual = '/\b(foto|fotos|video|v[ií]deo|videos|v[ií]deos|imagen|im[aá]genes|'
            .'material|antes y despu[eé]s|resultados?)\b/iu';

        return (bool) preg_match($repetir, $last) && (bool) preg_match($visual, $last);
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
     * Todas las herramientas disponibles en esta conversación.
     *
     * OJO con el orden de dependencias: el escalamiento a humano NO se cuelga de
     * `canSchedule()` como las de agendar. Es deliberado — es la ruta que la
     * política de WhatsApp exige tener siempre disponible, y justo cuando algo
     * se rompe (agenda caída por OAuth vencido, pasarela sin responder) es
     * cuando más falta hace poder pasarle la paciente a una persona.
     *
     * @return array<int,array<string,mixed>>
     */
    private function tools(): array
    {
        $tools = [$this->handoffTool(), $this->remindersTool()];

        if ($this->canSchedule()) {
            $tools = array_merge($tools, $this->bookingTools());
        }

        return $tools;
    }

    /**
     * Activar o desactivar los recordatorios de cita para esta paciente.
     *
     * Tampoco depende de `canSchedule()`: los recordatorios los manda su propio
     * comando, al margen de que la agenda esté conectada o no. Si dependiera,
     * la paciente pediría que no le escribamos y no habría dónde anotarlo.
     *
     * @return array<string,mixed>
     */
    private function remindersTool(): array
    {
        return [
            'name' => 'recordatorios_de_cita',
            'description' => 'Activa o desactiva los recordatorios automáticos de cita para esta paciente. Úsala EN CUANTO la paciente pida que no le escribamos recordatorios (o que se los volvamos a enviar). Es la única forma de que su decisión se cumpla de verdad: decírselo sin usarla no sirve de nada.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'recibir' => [
                        'type' => 'boolean',
                        'description' => 'false = la paciente NO quiere recibir más recordatorios. true = quiere volver a recibirlos.',
                    ],
                ],
                'required' => ['recibir'],
            ],
        ];
    }

    /**
     * Pasarle la conversación a una persona del consultorio.
     *
     * @return array<string,mixed>
     */
    private function handoffTool(): array
    {
        return [
            'name' => 'escalar_a_humano',
            'description' => 'Entrega esta conversación al equipo humano del consultorio: deja de responder tú y marca el chat como pendiente de atender para que una persona le escriba a la paciente. Úsala en el MISMO turno en el que le anuncias a la paciente que la derivas, nunca después.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'motivo' => [
                        'type' => 'string',
                        'description' => 'Por qué se escala, en pocas palabras. Por ejemplo "la paciente pidió hablar con la doctora", "molesta por un cobro", "consulta médica específica".',
                    ],
                    'resumen' => [
                        'type' => 'string',
                        'description' => 'Resumen breve de lo conversado, para que la persona que atienda no tenga que leerlo todo. No incluyas datos clínicos ni financieros que la paciente haya escrito.',
                    ],
                ],
                'required' => ['motivo'],
            ],
        ];
    }

    /**
     * Herramientas que el bot puede usar para agendar (Anthropic tool use).
     *
     * @return array<int,array<string,mixed>>
     */
    private function bookingTools(): array
    {
        $tools = [
            [
                'name' => 'consultar_disponibilidad',
                'description' => 'Devuelve los horarios LIBRES reales de la clínica (fechas y horas exactas), ya descontando lo ocupado en la agenda y respetando el horario de atención. Úsala ANTES de proponer horarios y ofrécele al paciente las fechas y horas concretas que devuelva, nunca términos vagos como "mañana". Puedes revisar varios días a la vez con el parámetro "dias".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'fecha' => ['type' => 'string', 'description' => 'Fecha de inicio a consultar en formato YYYY-MM-DD. Si se omite, se usa hoy.'],
                        'dias' => ['type' => 'integer', 'description' => 'Cuántos días consecutivos revisar a partir de la fecha (1 a 7). Por defecto 3. Usa más si el paciente pregunta "esta semana" o no tiene un día fijo.'],
                        'servicio' => ['type' => 'string', 'description' => 'Servicio o motivo de la cita, si ya lo sabes. Es IMPORTANTE pasarlo: cada procedimiento dura distinto, y sin él se calculan los huecos con una duración estándar y puede ofrecerse una hora donde el procedimiento no cabe.'],
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

        // Con Mercado Pago conectado, el pago deja de ser una promesa: el bot genera
        // un link propio para esa paciente y le pregunta a la pasarela si de verdad pagó.
        if (MercadoPagoService::fromConfig()->isConfigured()) {
            $tools[] = [
                'name' => 'generar_link_pago',
                'description' => 'Genera un link de pago ÚNICO para esta paciente y devuelve la URL para compartirle. Úsala cuando ya acordaron día y hora y le vas a pedir el pago de la valoración. No inventes links: usa siempre el que devuelva esta herramienta. IMPORTANTE: pásale el día, la hora y el nombre que ya acordaron — con eso la cita se agenda SOLA en cuanto entre el pago, aunque la paciente no vuelva a escribir.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'concepto' => ['type' => 'string', 'description' => 'Qué se está cobrando, por ejemplo "Valoración médica". Si se omite, se cobra la valoración.'],
                        'fecha_hora' => ['type' => 'string', 'description' => 'Día y hora que la paciente ya eligió, en formato YYYY-MM-DDTHH:MM (hora local de la clínica). Inclúyelo SIEMPRE que ya lo hayan acordado.'],
                        'nombre_paciente' => ['type' => 'string', 'description' => 'Nombre de la paciente, para poder agendar en cuanto pague.'],
                        'servicio' => ['type' => 'string', 'description' => 'Tratamiento o motivo de la cita, tal como lo dijo la paciente.'],
                        'telefono' => ['type' => 'string', 'description' => 'Teléfono de contacto, si lo tienes.'],
                        'correo' => ['type' => 'string', 'description' => 'Correo, si lo tienes.'],
                        'duracion_minutos' => ['type' => 'integer', 'description' => 'Duración en minutos. Si se omite, se usa la del servicio o 45.'],
                    ],
                    'required' => [],
                ],
            ];
            $tools[] = [
                'name' => 'verificar_pago',
                'description' => 'Consulta en la pasarela si la paciente YA pagó el link que se le compartió. Úsala SIEMPRE que diga que pagó, antes de agendar. Devuelve si el pago está confirmado o todavía pendiente.',
                // `properties` va como objeto vacío, NO como array: en PHP un []
                // se serializa a `[]` y la API de Claude rechaza la petición
                // entera con "input_schema.properties: Input should be an object",
                // dejando al bot sin poder responder nada.
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ];
        }

        return $tools;
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
                'escalar_a_humano' => $this->toolHandoff($input),
                'recordatorios_de_cita' => $this->toolReminders($input),
                'consultar_disponibilidad' => $this->toolAvailability($input),
                'agendar_cita' => $this->toolBook($input),
                'generar_link_pago' => $this->toolPaymentLink($input),
                'verificar_pago' => $this->toolCheckPayment(),
                default => 'Herramienta desconocida.',
            };
        } catch (Throwable $e) {
            return 'ERROR al ejecutar la herramienta: '.$e->getMessage();
        }
    }

    /**
     * Entrega la conversación al equipo humano.
     *
     * Apaga al asistente en ESTE chat y la marca como pendiente de atender. La
     * despedida sí sale: `ProcessWhatsAppMessage` comprueba `bot_enabled` ANTES
     * de pedir la respuesta, así que el mensaje de este turno se envía completo
     * y el silencio empieza con el siguiente mensaje de la paciente.
     *
     * @param  array<string,mixed>  $input
     */
    private function toolHandoff(array $input): string
    {
        $conversation = $this->conversation;

        // En el panel del Asistente no hay chat real que marcar: la doctora está
        // probando. Se deja pasar sin tocar nada (esas conversaciones ni siquiera
        // salen en la bandeja, así que la marca quedaría colgada sin que nadie la vea).
        if (! $conversation?->exists || $conversation->channel === 'panel') {
            return 'Escalamiento simulado (esta es una conversación de prueba, no hay chat real que marcar). '
                .'Despídete igual como lo harías con una paciente.';
        }

        $motivo = trim((string) ($input['motivo'] ?? ''));
        $resumen = trim((string) ($input['resumen'] ?? ''));

        $nota = trim($motivo.($resumen !== '' ? " — {$resumen}" : ''));

        $conversation->forceFill([
            'bot_enabled' => false,
            'bot_paused_at' => now(),
            'escalated_at' => now(),
            'escalation_reason' => Str::limit($nota !== '' ? $nota : 'La paciente pidió hablar con una persona.', 490),
        ])->save();

        Log::info('El asistente escaló una conversación a humano', [
            'conversation_id' => $conversation->id,
            'motivo' => $motivo,
        ]);

        return 'Listo: el chat quedó marcado como pendiente de atender y tú ya no responderás más aquí. '
            .'Despídete AHORA, en este mismo mensaje: dile con calidez que una persona del consultorio le escribirá por este mismo chat. '
            .'No prometas un tiempo exacto de respuesta y no le pidas más datos.';
    }

    /**
     * Guarda (o retira) la petición de no recibir recordatorios de cita.
     *
     * Se anota por TELÉFONO, no por lead: el comando de recordatorios manda al
     * número que salga de la cita, que no siempre tiene un lead vinculado.
     *
     * @param  array<string,mixed>  $input
     */
    private function toolReminders(array $input): string
    {
        $conversation = $this->conversation;
        $recibir = (bool) ($input['recibir'] ?? true);

        if (! $conversation?->exists || $conversation->channel === 'panel') {
            return 'Preferencia registrada (conversación de prueba). Confírmaselo a la paciente con naturalidad.';
        }

        $telefono = $conversation->lead?->phone;

        if (blank($telefono) || strlen(ReminderOptOut::normalize($telefono)) !== 10) {
            return 'No pude guardar la preferencia porque no tenemos un teléfono válido de esta paciente. '
                .'Pídeselo con naturalidad y vuelve a intentarlo.';
        }

        $clave = ReminderOptOut::normalize($telefono);

        if ($recibir) {
            ReminderOptOut::where('user_id', $conversation->user_id)->where('phone', $clave)->delete();

            return 'Listo: la paciente vuelve a recibir los recordatorios de sus citas. Confírmaselo con calidez.';
        }

        ReminderOptOut::updateOrCreate(
            ['user_id' => $conversation->user_id, 'phone' => $clave],
            ['lead_id' => $conversation->lead_id, 'source' => 'bot'],
        );

        Log::info('Paciente excluida de los recordatorios de cita', [
            'conversation_id' => $conversation->id,
            'lead_id' => $conversation->lead_id,
        ]);

        return 'Listo: ya no se le enviarán recordatorios automáticos de cita. Confírmaselo con calidez y aclárale '
            .'que sí puede seguir escribiéndote por aquí cuando quiera.';
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

        // Cuánto ocupará DE VERDAD la cita, que no es lo mismo que cada cuánto
        // empieza un hueco. Sin esto se ofrecían horas donde el procedimiento no
        // cabía: con huecos de 30 min, las 13:30 se medían como 13:30-14:00 y
        // salían libres aunque a las 14:00 hubiera algo, pero la cita real de 45
        // min llegaba hasta las 14:15 y `createBooking` la rechazaba. La paciente
        // ya había PAGADO para entonces, así que el error se cobraba.
        $duracion = max(15, (int) ($this->resolveService((string) ($input['servicio'] ?? ''))?->duration_minutes ?: self::DURACION_POR_DEFECTO));

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

        // Un horario con un pago en curso está apartado aunque todavía no sea
        // una cita en Google: si no se descuenta aquí, Lore se lo ofrece a una
        // segunda paciente, le cobra, y al entrar el pago no hay dónde meterla.
        // Las reservas de ESTA conversación no cuentan: su hora sigue libre
        // para ella.
        $busy = array_merge($busy, PaymentLink::heldSlots($this->user->id, $tz, $this->conversation?->id));

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
            // Se AVANZA de $slotMin en $slotMin (cada cuánto puede empezar una
            // cita) pero se COMPRUEBA $duracion (cuánto ocupa), y el hueco solo
            // vale si la cita entera cabe antes de cerrar.
            for ($t = $open->copy(); $t->copy()->addMinutes($duracion)->lte($close); $t->addMinutes($slotMin)) {
                $slotStart = $t->copy();
                $slotEnd = $t->copy()->addMinutes($duracion);

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
     * Genera un link de pago propio de esta paciente. La `reference` lleva el id
     * de la conversación, que es lo que permite saber después quién pagó.
     *
     * @param  array<string,mixed>  $input
     */
    private function toolPaymentLink(array $input): string
    {
        $pasarela = MercadoPagoService::fromConfig();
        $monto = Settings::valoracionAmount();
        $concepto = trim((string) ($input['concepto'] ?? '')) ?: 'Valoración médica';

        $referencia = 'conv-'.($this->conversation?->id ?? 0).'-'.now()->timestamp;

        // La cita que ya acordaron viaja con el link. Es lo que permite que
        // `payments:check-pending` agende solo cuando entre el pago: sin esto el
        // horario solo existe en la conversación y hay que esperar a que la
        // paciente vuelva a escribir.
        $servicioPedido = trim((string) ($input['servicio'] ?? ''));

        // La duración se resuelve y se GUARDA aquí, no al agendar: es la que
        // determina cuánto tiempo queda apartado el hueco mientras paga. Sin
        // esto se apartarían 45 minutos para un procedimiento de 90 y alguien
        // podría meterse encima.
        $duracion = (int) ($input['duracion_minutos'] ?? 0)
            ?: ($this->resolveService($servicioPedido)?->duration_minutes ?? 0)
            ?: 45;

        $reserva = array_filter([
            'fecha_hora' => trim((string) ($input['fecha_hora'] ?? '')) ?: null,
            'nombre_paciente' => trim((string) ($input['nombre_paciente'] ?? '')) ?: $this->conversation?->lead?->name,
            'servicio' => $servicioPedido ?: null,
            'telefono' => trim((string) ($input['telefono'] ?? '')) ?: $this->conversation?->lead?->phone,
            'correo' => trim((string) ($input['correo'] ?? '')) ?: null,
            'duracion_minutos' => $duracion,
        ], fn ($v) => filled($v));

        $link = $pasarela->createLink(
            $monto,
            $referencia,
            $concepto,
            // El link vive 24 horas: suficiente para que pague sin que quede vivo para siempre.
            now()->addDay(),
        );

        PaymentLink::create([
            'user_id' => $this->user->id,
            'conversation_id' => $this->conversation?->id,
            'lead_id' => $this->conversation?->lead_id,
            'reference' => MercadoPagoService::sanitizeReference($referencia),
            'payment_link' => $link['payment_link'],
            'url' => $link['url'],
            'amount' => $monto,
            'description' => $concepto,
            'booking' => $reserva ?: null,
            'status' => 'ACTIVE',
            'expires_at' => now()->addDay(),
        ]);

        $formateado = '$'.number_format($monto, 0, ',', '.');

        // Solo se le promete el agendamiento automático si de verdad quedó
        // guardado el horario; si no, la paciente TIENE que volver a escribir.
        $aviso = filled($reserva['fecha_hora'] ?? null)
            ? 'La cita quedó reservada con ese día y hora: en cuanto entre el pago se agenda sola y se le confirma, aunque no vuelva a escribir. Dile que le confirmarás apenas se refleje.'
            : 'OJO: no guardaste día y hora, así que la cita NO se agendará sola. Pídele que te avise por aquí cuando pague.';

        return "Link de pago generado por {$formateado} ({$concepto}). Compártele EXACTAMENTE esta URL: {$link['url']} "
            .'Dile que puede pagar con tarjeta débito o crédito, PSE o Efecty. '.$aviso;
    }

    /**
     * Le pregunta a la pasarela si el último link de esta paciente ya fue pagado.
     * Es la diferencia entre creerle y comprobarlo.
     */
    private function toolCheckPayment(): string
    {
        $link = $this->latestPaymentLink();

        if (! $link) {
            return 'ERROR: todavía no se le ha generado un link de pago a esta paciente. Genera uno con generar_link_pago antes de verificar.';
        }

        if ($link->isPaid()) {
            return 'PAGO CONFIRMADO. Ya puedes agendar la cita.';
        }

        // Se consulta por la referencia y no por la preferencia: una misma
        // preferencia puede acumular varios intentos y lo que importa es si
        // alguno quedó aprobado.
        $estado = MercadoPagoService::fromConfig()->linkStatus($link->reference);

        // Si nadie pagó y el link ya venció, hay que generar uno nuevo.
        $vencido = $estado['status'] === 'PENDING'
            && $link->expires_at
            && $link->expires_at->isPast();

        $final = $vencido ? 'EXPIRED' : $estado['status'];

        $link->forceFill([
            'status' => $final,
            'payment_method' => $estado['payment_method'],
            'paid_at' => $final === PaymentLink::PAGADO ? now() : null,
            'checked_at' => now(),
        ])->save();

        return match ($final) {
            'PAID' => 'PAGO CONFIRMADO. Ya puedes agendar la cita.',
            'PROCESSING' => 'El pago está en proceso, todavía no se confirma. Pídele que espere un momento y vuelve a verificar; NO agendes aún.',
            'REJECTED' => 'El pago fue RECHAZADO. Avísale con amabilidad y ofrécele intentar de nuevo con el mismo link u otro medio de pago. NO agendes.',
            'REFUNDED' => 'El pago fue devuelto o revertido, así que la cita no está cubierta. Avísale con amabilidad. NO agendes.',
            'EXPIRED', 'CANCELLED' => 'El link venció o fue cancelado. Genera uno nuevo con generar_link_pago. NO agendes.',
            default => 'El pago AÚN NO figura como realizado. No agendes todavía; dile con amabilidad que en cuanto se refleje le confirmas la cita.',
        };
    }

    /** Último link generado en esta conversación. */
    private function latestPaymentLink(): ?PaymentLink
    {
        if (! $this->conversation) {
            return null;
        }

        return PaymentLink::where('conversation_id', $this->conversation->id)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function toolBook(array $input): string
    {
        $link = null;

        // Con la pasarela conectada, el pago deja de depender de la palabra de
        // la paciente ni de que el modelo respete el prompt: si no hay un link
        // pagado de verdad, aquí no se agenda.
        if (MercadoPagoService::fromConfig()->isConfigured()) {
            $link = $this->latestPaymentLink();

            if (! $link) {
                return 'ERROR: no puedes agendar todavía porque esta paciente no ha pagado la valoración. Genera el link con generar_link_pago y compártelo.';
            }

            if (! $link->isPaid()) {
                return 'ERROR: el pago de esta paciente NO está confirmado en la pasarela. Verifícalo con verificar_pago; si sigue pendiente, no agendes y dile con amabilidad que en cuanto se refleje el pago le confirmas la cita.';
            }

            // El barrido automático pudo haberla agendado ya al detectar el
            // pago. Sin esta guarda, que la paciente escriba "ya pagué" después
            // le crearía una segunda cita a la misma hora.
            if ($link->appointment_id) {
                $ya = $link->appointment;
                $cuando = $ya?->starts_at?->format('Y-m-d h:i a') ?? 'la hora acordada';

                return "La cita YA está agendada para {$cuando} (se agendó sola en cuanto entró el pago). "
                    .'NO vuelvas a agendar: solo confírmasela con calidez y recuérdale la dirección.';
            }
        }

        $resultado = $this->createBooking($input);

        if ($link && $resultado['appointment']) {
            $link->forceFill(['appointment_id' => $resultado['appointment']->id])->save();
        }

        return $resultado['message'];
    }

    /**
     * Crea la cita (pipeline + agenda + Google Calendar) y devuelve el resultado.
     *
     * Es pública y separada de `toolBook` a propósito: `payments:check-pending`
     * agenda por su cuenta cuando entra un pago sin que la paciente vuelva a
     * escribir, y necesita exactamente esta lógica sin la parte conversacional.
     *
     * @param  array<string,mixed>  $input
     * @return array{message:string, appointment:?Appointment}
     */
    public function createBooking(array $input): array
    {
        $tz = Settings::googleTimezone();
        $start = Carbon::parse($input['fecha_hora'], $tz);

        // Resuelve el servicio a partir del texto del paciente (admite nombres
        // comerciales como "botox" aunque el catálogo use el nombre clínico).
        $requested = trim((string) ($input['servicio'] ?? ''));
        $service = $this->resolveService($requested);

        $duration = (int) ($input['duracion_minutos'] ?? $service?->duration_minutes ?: self::DURACION_POR_DEFECTO);
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
                return [
                    'message' => "ERROR: el horario de las {$start->format('h:i a')} ya está ocupado. No agendes ahí; ofrece otro horario libre.",
                    'appointment' => null,
                ];
            }
        }

        // Segunda red: el hueco puede estar apartado por otra paciente que está
        // pagando ahora mismo y cuyo pago todavía no llegó, así que en Google
        // aún no aparece nada.
        foreach (PaymentLink::heldSlots($this->user->id, $tz, $this->conversation?->id) as $reserva) {
            if ($start->lt($reserva['end']) && $end->gt($reserva['start'])) {
                return [
                    'message' => "ERROR: el horario de las {$start->format('h:i a')} está apartado por otra paciente que tiene un pago en curso. No agendes ahí; ofrécele otro horario libre.",
                    'appointment' => null,
                ];
            }
        }

        // Vincula al paciente en el pipeline. Si la conversación ya tiene lead
        // (el que entró por WhatsApp) ese manda: es el teléfono real. Si no, se
        // busca por teléfono/nombre y se crea, para que la cita no quede huérfana.
        $lead = $this->conversation?->lead ?: PatientLeads::resolve(
            $this->user,
            $input['nombre_paciente'] ?? null,
            $input['telefono'] ?? null,
            [
                'stage_id' => PatientLeads::stageId($this->user, 'agendado'),
                'channel' => $this->conversation?->channel ?: 'whatsapp',
                'source' => 'bot',
                'last_contact_at' => now(),
            ],
        );

        // El paciente acaba de agendar: se mueve a «Agendado» y se anota qué
        // tratamiento pidió, para que la doctora lo vea en el tablero.
        if ($lead) {
            $agendado = PatientLeads::stageId($this->user, 'agendado');
            $cambios = ['last_contact_at' => now()];
            if ($agendado && $lead->stage_id !== $agendado) {
                $cambios['stage_id'] = $agendado;
                $cambios['position'] = PatientLeads::nextPosition($this->user, $agendado);
            }
            if (blank($lead->service_interest) && ($service?->name || $requested !== '')) {
                $cambios['service_interest'] = $service?->name ?: $requested;
            }
            if (blank($lead->phone) && filled($input['telefono'] ?? null)) {
                $cambios['phone'] = $input['telefono'];
            }
            $lead->forceFill($cambios)->save();
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

            // La cita SÍ existe: se devuelve para que quien llame la dé por
            // creada y no la intente otra vez. Lo que falló es el calendario.
            return [
                'message' => 'La cita quedó guardada pero no se pudo poner en el calendario: '.$e->getMessage()
                    .' Avísale al paciente que la confirmarás en breve.',
                'appointment' => $appointment,
            ];
        }

        $when = $start->format('Y-m-d').' a las '.$start->format('h:i a');

        return [
            'message' => 'Cita agendada con éxito para '.$input['nombre_paciente'].' el '.$when
                .($service ? ' ('.$service->name.')' : '')
                .'. Quedó registrada en la agenda. Confírmasela al paciente con calidez y recuérdale la dirección de la clínica.',
            'appointment' => $appointment,
        ];
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
        // Ya no se condiciona a que haya campaña: el texto del anuncio llega en
        // el referral de la conversación y sirve aunque la campaña no esté
        // importada. El método decide si hay algo que decir.
        $campaignBlock = $this->campaignPrompt($campaign);

        $valoracionLinkLine = filled($c['clinic_payment_link'] ?? null)
            ? "\n- Link para pagar la valoración en línea: {$c['clinic_payment_link']}"
            : '';
        $landingLine = filled($c['clinic_landing'] ?? null)
            ? "\n- Página web con más información: {$c['clinic_landing']}"
            : '';

        // Hay pago en línea si la pasarela genera un link por paciente o si
        // quedó configurado un link fijo a mano. Sin ninguno de los dos, el bot
        // no debe ofrecer ni mencionar links: solo los datos bancarios.
        //
        // Ojo con Mercado Pago: el link lo produce generar_link_pago, y esa
        // herramienta solo existe si canSchedule(). Con la agenda caída (OAuth
        // vencido, por ejemplo) la pasarela está configurada pero el bot NO
        // tiene cómo generar el link, así que no debe ofrecerlo.
        $pagoEnLinea = ($this->canSchedule() && MercadoPagoService::fromConfig()->isConfigured())
            || filled($c['clinic_payment_link'] ?? null);

        // Ojo: el heredoc desindenta el literal, no lo interpolado. Estas líneas
        // van sin sangría para que el prompt quede alineado.
        $prioridadPagoLines = $pagoEnLinea
            ? "- Para pagar la valoración ofrece SIEMPRE primero el link de pago en línea: es el medio preferido y el más seguro."
                ."\n- Comparte los datos de transferencia, consignación o Nequi SOLO si el paciente los pide expresamente o te dice que no puede usar el link. No los ofrezcas de entrada ni los repitas si ya los enviaste en esta conversación."
                ."\n- El link de pago NO es una forma de pago genérica: es EXCLUSIVAMENTE para pagar la valoración. Nunca lo ofrezcas para pagar tratamientos u otros servicios."
            : "- Para pagar la valoración comparte los datos de transferencia, consignación o Nequi que aparecen arriba, y pídele que te AVISE por este chat cuando lo haya hecho."
                ."\n- NO hay link de pago en línea disponible: no lo menciones, no lo prometas y NUNCA inventes uno.";

        // Presentarse o no NO se le deja al criterio del modelo leyendo el
        // historial: una conversación de hace semanas él la ve como "en curso"
        // y suelta un "¡Hola! ¿En qué te ayudo?" sin decir quién es, que es
        // justo lo que no queremos en el primer contacto. Se decide aquí,
        // mirando si ya le habíamos escrito y hace cuánto.
        $ultimaNuestra = $this->conversation?->messages()
            ->where('role', 'assistant')
            ->latest('id')
            ->first()?->created_at;

        $presentacion = match (true) {
            // Primer contacto: la paciente no tiene idea de quién le escribe.
            $ultimaNuestra === null => "- Te llamas {$c['bot_name']}. Es la PRIMERA vez que hablas con esta paciente: preséntate en tu primer mensaje con tu nombre y el de la doctora — \"¡Hola! Soy {$c['bot_name']}, la asistente de la Dra. Jasmin Blanco 😊\". Hazlo aunque ella solo escriba \"hola\".",
            // Vuelve después de días: repetir el nombre suena a robot, pero sí
            // conviene recordarle desde dónde le escriben.
            $ultimaNuestra->lt(now()->subDay()) => "- Te llamas {$c['bot_name']}. Ya habías hablado con esta paciente hace días: salúdala de nuevo con calidez y menciona el consultorio de la Dra. Jasmin Blanco para ubicarla, sin repetir tu nombre salvo que te lo pregunte.",
            // Conversación viva.
            default => "- Te llamas {$c['bot_name']}. Ya vienes conversando con esta paciente, así que no vuelvas a presentarte salvo que te lo pregunte.",
        };

        // El bloque de campaña y el de presentación cambian en CADA conversación
        // (el anuncio del que viene, si es su primer mensaje). Van al final del
        // prompt a propósito: la caché de la API es un prefijo exacto, así que
        // ponerlos arriba —como estaban— invalidaba los ~13.000 tokens que vienen
        // después y no se cacheaba nada entre conversaciones.
        return <<<PROMPT
        Eres {$c['bot_name']}, asistente virtual de {$c['clinic_name']}, un consultorio de medicina estética premium dirigido por la Dra. Jasmin Blanco. Atiendes a pacientes por WhatsApp e Instagram con calidez y profesionalismo, como lo haría una asesora humana experimentada.

        # Tu identidad
        - No repitas tu nombre en cada mensaje; solo al presentarte o si te lo preguntan.
        - Eres la asistente VIRTUAL del consultorio: no eres la Dra. Blanco ni parte del equipo médico. Si el paciente pregunta si eres una persona real, un bot o una inteligencia artificial, acláralo con naturalidad y sin rodeos ("Soy la asistente virtual del consultorio; te ayudo con información y a agendar tu valoración con la doctora").
        - NUNCA afirmes ser humana ni te hagas pasar por la doctora.

        # Tu objetivo
        Resolver dudas frecuentes, construir valor y, cuando el paciente muestre interés real, motivar y ayudar a agendar una valoración. Nunca presionas; acompañas.

        # Tono y estilo
        - Español natural, cálido y cercano, pero profesional. Trato de "tú".
        - Usa el nombre del paciente si lo conoces. Muestra empatía genuina.

        # Longitud de tus mensajes (importante)
        Esto es WhatsApp: un mensaje largo se lee como un folleto y la gente lo salta. Además tardas más en escribirlo y el paciente te espera.
        - Escribe **3 o 4 líneas**, como las escribiría una persona. Un párrafo, no varios.
        - Da la información en varios turnos en vez de soltarla toda de golpe. Responde lo que preguntó y para.
        - Termina con UNA sola pregunta, no con dos o tres opciones encadenadas.
        - No repitas lo que el paciente acaba de decirte ni resumas lo que ya le dijiste antes.
        - Nada de listas de beneficios ni descripciones completas del tratamiento salvo que las pida: eso es para la valoración.
        - Excepción: cuando compartas datos de pago, un link o los horarios disponibles, cópialos completos aunque ocupen más.

        # Formato de los mensajes
        - Escribe en TEXTO PLANO, como un mensaje real de WhatsApp. WhatsApp e Instagram NO renderizan Markdown.
        - NO uses asteriscos (**), almohadillas (#), guiones bajos (_) ni ningún símbolo de Markdown para dar formato.
        - Para enumerar, usa frases cortas o viñetas con "•" o emojis discretos, no listas con "*" ni "-".
        - Para resaltar algo, usa MAYÚSCULAS con moderación o simplemente el contexto; nunca negritas con asteriscos.

        # Información de la clínica
        - Nombre: {$c['clinic_name']}
        - Dirección: {$c['clinic_address']}
        - Horarios: {$c['clinic_hours']}
        - Formas de pago: {$c['clinic_payment']}{$landingLine}{$valoracionLinkLine}

        # Pagos
        {$prioridadPagoLines}
        - Cuando compartas el link o los datos, cópialos EXACTAMENTE como los recibiste, sin acortarlos ni cambiar un solo número. Nunca inventes cuentas, llaves ni links: usa únicamente los que aparecen en esta información o los que te devuelva una herramienta.
        - NUNCA le pidas al paciente el número de su tarjeta, su cuenta bancaria, su documento de identidad, claves ni códigos de verificación. No los necesitas para nada.

        # Reglas importantes (cumplimiento sanitario)
        - NO diagnosticas ni recetas. Toda recomendación requiere una valoración médica presencial con la Dra. Blanco.
        - NO prometes resultados garantizados; cada paciente es diferente.
        - Menciona contraindicaciones generales cuando sea pertinente y sugiere valoración previa.
        - Respeta la normativa (Invima, SIC) y la protección de datos (habeas data): no insistas por datos sensibles innecesarios.
        - NO ofreces consulta, diagnóstico ni seguimiento médico a distancia por este chat: la atención clínica es siempre presencial con la doctora.
        - Si no tienes la información en tu base de conocimiento, no la inventes: ofrece resolverlo en la valoración o derivar al equipo humano.
        - No le prometas al paciente que TÚ le escribirás después (recordatorios, seguimientos, promociones) a menos que él lo autorice expresamente. Tú respondes cuando él escribe.
        - Si el paciente pide que NO le enviemos recordatorios de cita (o que se los volvamos a enviar), usa la herramienta recordatorios_de_cita en ese mismo momento. Decirle que sí y no usarla deja la promesa sin cumplir: los recordatorios le seguirían llegando.

        # Datos sensibles y privacidad (regla estricta)
        - NUNCA pidas datos financieros: números de tarjeta o de cuenta bancaria, claves, códigos de verificación (OTP) ni número de documento de identidad. Si el paciente los escribe por su cuenta, NO los repitas ni los confirmes en el chat.
        - NUNCA pidas información clínica: historia médica, diagnósticos, medicamentos, embarazo, enfermedades ni resultados de exámenes. Todo eso lo evalúa la doctora en la valoración presencial. Si el paciente lo cuenta por su cuenta, agradécele la confianza, NO lo repitas ni opines sobre ello, y llévalo con calidez a la valoración.
        - NUNCA pidas fotos del rostro, del cuerpo ni de la zona a tratar. Si el paciente las envía, no las analices ni las comentes clínicamente: dile con amabilidad que la doctora lo valorará en persona.
        - Pide solo lo mínimo necesario para agendar: nombre, teléfono y el motivo general de la consulta.
        - Nunca compartas ni comentes información de otros pacientes.

        # Escalamiento a humano (tienes una herramienta para esto)
        Para pasarle la conversación a una persona del consultorio usa la herramienta escalar_a_humano. Anunciarlo sin usarla NO sirve de nada: el paciente se queda esperando a alguien que nunca se entera. Úsala cuando:
        - el paciente pida hablar con una persona, con la doctora o con el equipo, aunque lo diga de pasada;
        - esté molesto, frustrado o se queje de un servicio, de un cobro o de una cita;
        - la consulta sea médica específica y requiera criterio profesional;
        - te pida algo que no puedes resolver con tus herramientas ni con tu base de conocimiento;
        - diga que ya pagó y el pago no aparezca después de comprobarlo, o haya cualquier enredo con el dinero.
        Llama la herramienta EN EL MISMO turno en que se lo dices, nunca "en el siguiente mensaje". Después de usarla despídete con calidez, avísale que una persona del consultorio le escribirá por este mismo chat y no prometas un tiempo exacto de respuesta.
        Ante la duda, escala: es preferible que responda una persona de más y no de menos.

        {$schedulingBlock}
        # Material visual (fotos y videos)
        Algunos servicios tienen fotos o videos disponibles; en la base de conocimiento aparecen marcados como "Material visual disponible" con su identificador.
        - Cuando el paciente pida ver fotos o videos del procedimiento, o cuando mostrar el material ayude a generar confianza y el servicio lo tenga, ENVÍALO.
        - Preséntalo siempre como material de REFERENCIA del procedimiento, nunca como una transformación garantizada ni como comparación "antes y después": los resultados varían en cada paciente y dependen de la valoración médica.
        - Nunca hagas sentir mal al paciente con su apariencia, ni señales "defectos", para motivarlo a un tratamiento.
        - Para enviarlo, escribe la etiqueta [[media:identificador]] en una línea aparte (por ejemplo [[media:limpieza-facial-profunda]]). El sistema la reemplaza automáticamente por las fotos/videos reales; el paciente NO ve la etiqueta.
        - Acompaña la etiqueta con una frase cálida y natural ("Te comparto unas imágenes para que veas el resultado 😊").
        - Solo usa identificadores que existan en la base de conocimiento. Si un servicio no tiene material visual, no inventes la etiqueta: ofrece resolverlo en la valoración.
        - Envía cada foto o video UNA sola vez por conversación. Si ya lo compartiste antes, NO lo reenvíes ni escribas que lo adjuntas de nuevo (puedes referirte a él con palabras). Solo reenvíalo si el paciente pide expresamente verlo otra vez.

        # Base de conocimiento de la clínica
        Responde ÚNICAMENTE con base en la siguiente información:

        {$this->knowledgeBase()}
        {$personaBlock}

        # Esta conversación en concreto
        {$presentacion}
        {$campaignBlock}
        PROMPT;
    }

    /**
     * Contexto de la campaña de Meta de la que viene el paciente, para que el bot
     * responda enfocado en el servicio y la oferta de ese anuncio.
     */
    private function campaignPrompt(?Campaign $campaign): string
    {
        // El texto del anuncio que el paciente ACABA de leer es el mejor
        // contexto que existe, y ya lo guardábamos sin usarlo: vive en
        // `conversations.referral`, que Meta rellena en el primer mensaje tras
        // tocar el anuncio. El nombre de la campaña, en cambio, es interno
        // ("VIDEOS METABOLICO -k-") y no le dice nada a nadie.
        //
        // Además el referral existe aunque la campaña no esté importada, así
        // que este bloque ya no depende de haberla podido resolver.
        $referral = (array) ($this->conversation?->referral ?? []);
        $anuncio = trim((string) ($referral['body'] ?? ''));
        $titular = trim((string) ($referral['headline'] ?? ''));

        if (! $campaign && $anuncio === '') {
            return '';
        }

        $lines = ['# De dónde viene este paciente'];

        if ($anuncio !== '') {
            $lines[] = 'Escribió justo después de ver un anuncio del consultorio. Este es el texto que leyó'
                .($titular !== '' ? " (titular: «{$titular}»)" : '').':';
            $lines[] = '"""';
            $lines[] = Str::limit($anuncio, 1200);
            $lines[] = '"""';
            $lines[] = 'Da por hecho que viene interesado en LO QUE PROMETE ese anuncio y orienta ahí tu primer mensaje, en vez de preguntarle en qué puedes ayudarle.';
            // Sin esto el modelo tiende a lucir el dato ("veo que viniste del
            // anuncio de..."), que al paciente le suena a que lo vigilan.
            $lines[] = 'NO menciones el anuncio ni des a entender que sabes lo que vio. Que se note en que aciertas con el tema, no en que lo dices.';
            // El anuncio es publicidad y promete resultados; el bot no puede.
            $lines[] = 'OJO: ese texto es material publicitario, no una promesa médica. Puedes hablar del tratamiento, pero NO repitas sus promesas como resultados garantizados ni asegures pérdidas de peso, tiempos ni efectos concretos.';
        }

        if ($campaign) {
            $campaign->loadMissing('service', 'media');
        }

        if ($campaign?->service) {
            $lines[] = "Servicio promocionado en el anuncio: {$campaign->service->name}. Prioriza este servicio en tu respuesta.";
        }

        if (filled($campaign?->offer)) {
            $lines[] = "Oferta / ángulo del anuncio (aprovéchalo con naturalidad, sin sonar a vendedor):\n{$campaign->offer}";
        }

        $usableMedia = ($campaign?->media ?? collect())->filter(fn ($m) => filled($m->resolved_url));
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

        // Solo la cabecera = no se averiguó nada útil (campaña sin servicio ni
        // oferta ni material, y sin texto de anuncio). Mejor no meter ruido.
        if (count($lines) === 1) {
            return '';
        }

        // "Reconociendo su interés" a secas empujaba al modelo a delatar que
        // sabe de dónde viene; se pide calidez sin mencionar el origen.
        $lines[] = 'Salúdalo con calidez, entra directo al tema que le interesa, resuelve sus dudas y guíalo a agendar una valoración. Si pregunta por otra cosa, ayúdalo igual.';

        return "\n".implode("\n", $lines)."\n";
    }

    /**
     * Instrucciones de agendamiento + fecha de hoy (solo si la agenda está conectada).
     */
    private function schedulingPrompt(): string
    {
        $tz = Settings::googleTimezone();
        $hoy = Carbon::now($tz)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');

        // Con pasarela conectada el pago se genera y se comprueba de verdad.
        // Sin ella caemos en la palabra de la paciente, y el medio depende de si
        // quedó un link fijo configurado: si no hay ninguno, solo datos bancarios.
        if (MercadoPagoService::fromConfig()->isConfigured()) {
            $pagoBlock = "\n        - Genera su link de pago con generar_link_pago (es único para ella) y compártele EXACTAMENTE la URL que devuelva. Nunca inventes ni reutilices links de otras pacientes."
                ."\n        - AL GENERARLO, pásale SIEMPRE el día y la hora que ya acordaron (fecha_hora) y el nombre de la paciente. Con eso la cita se agenda SOLA en cuanto entre el pago y se le confirma por este chat, aunque ella no vuelva a escribir. Si no se lo pasas, la cita queda en el aire hasta que ella escriba."
                ."\n        - Cuando diga que ya pagó, comprueba SIEMPRE con verificar_pago antes de agendar. Si el pago no está confirmado, no agendes: dile con amabilidad que aún no se refleja y que apenas entre le confirmas la cita."
                ."\n        - Si al agendar te dicen que la cita YA estaba agendada, no es un error: se agendó sola al entrar el pago. Solo confírmasela con calidez."
                ."\n        - El link acepta tarjeta débito o crédito, PSE y Efecty, así que no necesitas dar datos de cuentas bancarias.";
        } else {
            $medioPago = filled(Settings::botConfig()['clinic_payment_link'] ?? null)
                ? 'Compártele el link de pago en línea'
                : 'Compártele los datos de transferencia, consignación o Nequi que aparecen en la información de la clínica (no hay link de pago en línea: no lo menciones ni lo inventes)';

            $pagoBlock = "\n        - {$medioPago} y pídele que te AVISE por este chat cuando ya lo haya hecho."
                ."\n        - Considera el pago confirmado si te lo dice de forma clara y explícita.";
        }

        return <<<PROMPT
        # Agendamiento de citas (tienes la agenda conectada)
        Hoy es {$hoy} (zona horaria {$tz}). Puedes agendar citas tú mismo.
        - Cuando el paciente quiera agendar, pide de forma natural lo que falte: su nombre, el servicio o motivo, y el día y la hora que prefiere. Pide el teléfono si aún no lo tienes.
        - Interpreta expresiones como "mañana", "el viernes" o "la próxima semana" según la fecha de hoy.
        - Para sugerir horarios, usa consultar_disponibilidad (devuelve los huecos LIBRES reales con fecha y hora exactas). Ofrécele al paciente esas fechas y horas concretas (por ejemplo "tengo disponible el martes 24 a las 9:00 a. m., 10:30 a. m. o 3:00 p. m."), NUNCA términos vagos como "mañana" o "más tarde".
        - Si el paciente no dio un día fijo, consulta varios días a la vez (parámetro "dias") y ofrécele las primeras opciones de los próximos días.
        - No ofrezcas un horario que no aparezca en la lista de disponibles, y respeta siempre el horario de atención.
        - VUELVE A LLAMAR a consultar_disponibilidad cada vez que menciones horarios, aunque ya los hayas consultado en esta misma conversación y aunque la paciente solo pida que se los repitas. NUNCA los copies de un mensaje anterior tuyo: la agenda cambia a cada momento y una hora que estaba libre hace un rato puede estar ocupada ahora. Repetir la lista vieja hace que le cobremos a la paciente por una hora que ya no existe.
        - Cuando el paciente confirme un día y una hora, NO agendes todavía: primero debe PAGAR la valoración (ver la regla obligatoria abajo). No afirmes que quedó agendada hasta que la herramienta agendar_cita lo confirme.
        - SIEMPRE incluye en el parámetro "servicio" el tratamiento que mencionó el paciente (por ejemplo "botox", "limpieza facial", "ácido hialurónico"), aunque use un nombre comercial; el sistema lo asocia con el servicio del catálogo. Sin esto, la cita queda sin tratamiento.
        - Después de agendar, confírmale con calidez el día y la hora, y recuérdale la dirección de la clínica.
        - Al confirmar la cita, avísale que le enviaremos un recordatorio por este mismo chat un día antes y otro un par de horas antes, y que si prefiere no recibirlos solo tiene que decírtelo. Este aviso es obligatorio: es el permiso del paciente para escribirle después.
        - Si el horario que pide ya está ocupado, discúlpate y ofrécele las opciones libres más cercanas.

        # Pago de la valoración OBLIGATORIO antes de agendar (regla estricta)
        - NUNCA uses agendar_cita si el paciente todavía no ha pagado la valoración. El pago es el requisito para apartar el cupo; sin pago no hay cita.
        - Flujo: ayúdale a elegir un día y una hora disponibles, pero antes de agendar dile con calidez que para apartar ese cupo necesita pagar la valoración.{$pagoBlock}
        - NUNCA le pidas la captura del comprobante, ni los datos de su tarjeta o cuenta.
        - Si solo dice "ahorita pago" o "ya voy a pagar", NO agendes: espera con amabilidad a que el pago se concrete.
        - Si el paciente envía una imagen por su cuenta mientras coordinan la cita, agradécele pero NO transcribas ni comentes los datos que aparezcan en ella.
        - Apenas el pago esté confirmado, usa agendar_cita de INMEDIATO con el día y la hora que el paciente había elegido, agradécele el pago y confírmale la cita con calidez.

        # Política de cancelación
        - Si avisa con MÁS de 24 horas de anticipación respecto a la hora de su cita, SÍ se le devuelve el valor de la valoración.
        - Si avisa cuando faltan menos de 24 horas, o no se presenta, PIERDE el valor de la valoración: no se le devuelve, no le queda a favor y no se le abona a otra fecha.
        - Las 24 horas se cuentan contra la HORA de la cita, no contra el día: para una cita el jueves a las 3:00 p. m., el plazo se cierra el miércoles a las 3:00 p. m. Si te preguntan si están a tiempo, cuéntalo con la fecha y hora reales de su cita.
        - Dilo AL CONFIRMAR la cita, en una sola frase, con calidez y sin sonar a advertencia, junto al aviso de los recordatorios. Es obligatorio: tiene que quedarle claro ANTES de que le afecte, no cuando ya la incumplió, porque es dinero que ya pagó.
        - Si pregunta por la política, explícasela con claridad y sin dramatizar.
        - TÚ NO puedes cancelar ni reprogramar una cita ya agendada, ni hacer devoluciones: no tienes ninguna herramienta para nada de eso. Si la paciente quiere cancelar, moverla o que le devuelvan el dinero, escálalo a una persona con escalar_a_humano y dile que el equipo del consultorio la contacta. NUNCA le digas que ya quedó cancelada, reprogramada o devuelta, ni que "se lo gestionas": no es cierto y se quedaría esperando.
        - Puedes confirmarle si le corresponde o no la devolución según el plazo —eso es informarla, no tramitarla—, pero el trámite lo hace una persona.
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
