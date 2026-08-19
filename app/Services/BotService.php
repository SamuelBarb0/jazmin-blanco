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
use Illuminate\Support\Collection;
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
     * Tiene que estar de acuerdo en TRES sitios —ofrecer horarios, apartar el
     * hueco mientras paga y agendar—; cuando no lo estaban se ofrecían huecos
     * donde la cita no cabía. Vive en `Settings` y no como constante para que
     * la clínica pueda cambiarla sin tocar código.
     */
    private function duracionPorDefecto(): int
    {
        return Settings::defaultAppointmentMinutes();
    }

    /**
     * Conversación que se está atendiendo. La guardamos para que al agendar se
     * pueda vincular la cita al paciente que ya venía chateando, en vez de
     * adivinarlo por el teléfono que escriba en el mensaje.
     */
    private ?Conversation $conversation = null;

    /**
     * Caché de `paginaDeOrigen()`. `false` = todavía no se calculó; `null` = se
     * calculó y no venía de la web. El prompt se arma en cada turno y el primer
     * mensaje de la conversación no cambia, así que se resuelve una sola vez.
     *
     * @var array{url:string, slug:string, service:?Service}|null|false
     */
    private array|null|false $paginaDeOrigen = false;

    /**
     * Datos de transferencia que hay que anexar a la respuesta porque se acaba
     * de apartar una cita por ese medio. Ver `conDatosDePago()`.
     */
    private ?string $datosPagoPorAnexar = null;

    public function __construct(
        private readonly User $user,
        private readonly AnthropicService $ai,
    ) {}

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
                $block['input'] = new \stdClass;
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

        $result['text'] = $this->conDatosDePago($result['text']);

        return $result;
    }

    /**
     * Añade los datos de transferencia cuando se acaba de apartar una cita por
     * ese medio y el modelo no los escribió.
     *
     * Se hace por código y no pidiéndoselo al prompt porque pidiéndoselo NO
     * funciona: se intentó como regla del system prompt, como excepción a la
     * norma de brevedad y hasta metiendo los datos en el propio resultado de la
     * herramienta, y en las cinco pruebas el modelo confirmó la cita y se los
     * saltó. Compiten demasiadas instrucciones obligatorias en ese mismo
     * mensaje (recordatorios, política de cancelación, dirección).
     *
     * Y aquí equivocarse cuesta caro: la paciente se queda con el cupo apartado,
     * creyendo que debe transferir, y sin saber a qué cuenta.
     *
     * Solo se añade si NO están ya: si el modelo los escribió, se respeta su
     * redacción y no se duplica nada.
     */
    private function conDatosDePago(string $text): string
    {
        if (blank($this->datosPagoPorAnexar)) {
            return $text;
        }

        $datos = $this->datosPagoPorAnexar;
        $this->datosPagoPorAnexar = null;

        // Tienen que estar TODOS los números largos, no basta con uno.
        //
        // Antes valía con encontrar uno solo: se asumía que si el modelo había
        // copiado algo, lo había copiado todo. Con una sola cuenta y el Nequi
        // colaba; desde que la clínica tiene Davivienda, Bancolombia y Nequi
        // (13/08/2026), que el modelo escriba únicamente el Nequi y se salte
        // las dos cuentas es un desenlace perfectamente posible — y el código
        // lo habría dado por bueno, dejando a la paciente con el cupo apartado
        // y sin saber a qué cuenta transferir.
        //
        // Si falta alguno se anexa el bloque entero, aun a riesgo de repetir un
        // número que el modelo ya había escrito: en dinero, repetir es un
        // defecto de estilo y faltar es que la paciente no pueda pagar.
        preg_match_all('/\d{7,}/', $datos, $numeros);
        $numerosUnicos = array_unique($numeros[0]);

        if ($numerosUnicos !== []) {
            $faltantes = array_filter($numerosUnicos, fn (string $n) => ! str_contains($text, $n));

            if ($faltantes === []) {
                return $text;
            }
        }

        return trim($text)."\n\n".trim($datos);
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
                        'duracion_minutos' => ['type' => 'integer', 'description' => 'Duración en minutos. NO la inventes: omítela salvo que la doctora haya dicho expresamente cuánto dura ese procedimiento. Si se omite se usa la del servicio y, si el servicio no la trae, '.$this->duracionPorDefecto().' minutos, que es lo que dura una valoración.'],
                        'notas' => ['type' => 'string', 'description' => 'Notas u observaciones.'],
                        'pago_por_transferencia' => [
                            'type' => 'boolean',
                            'description' => 'true SOLO si la paciente eligió pagar por transferencia o Nequi en vez del link. La cita se aparta igual, pero queda marcada para que la doctora confirme en el banco que el dinero llegó. NO lo pongas en true por insistencia, prisa ni promesas de pagar después: solo cuando de verdad escogió ese medio.',
                        ],
                    ],
                    'required' => ['nombre_paciente', 'fecha_hora'],
                ],
            ],
            [
                'name' => 'reagendar_cita',
                'description' => 'Mueve a otro día u hora una cita que la paciente YA tiene agendada. Úsala cuando pida cambiarla, moverla, correrla o adelantarla. NO vuelve a cobrar: la valoración que ya pagó se conserva y pasa a la fecha nueva. Comprueba antes con consultar_disponibilidad que la hora nueva esté libre.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'fecha_hora' => ['type' => 'string', 'description' => 'Nueva fecha y hora de inicio en formato YYYY-MM-DDTHH:MM (hora local de la clínica). La cita conserva la duración que ya tenía.'],
                        'cita_id' => ['type' => 'integer', 'description' => 'Id de la cita que se va a mover. Solo hace falta cuando la paciente tiene varias citas próximas; en ese caso la herramienta te devuelve los ids para que le preguntes cuál. Es un número interno: NO se lo menciones a la paciente.'],
                        'motivo' => ['type' => 'string', 'description' => 'Por qué la mueve, en pocas palabras, si lo dijo. Queda anotado en la cita para la doctora.'],
                    ],
                    'required' => ['fecha_hora'],
                ],
            ],
            [
                'name' => 'cancelar_cita',
                'description' => 'Cancela una cita que la paciente YA tiene agendada: la retira de la agenda y del calendario de la doctora, y deja de enviarle recordatorios. Úsala SOLO cuando diga con claridad que no va a asistir y no quiera otra fecha; si solo quiere cambiar el día, usa reagendar_cita. Esta herramienta NO devuelve dinero: eso lo tramita una persona.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'cita_id' => ['type' => 'integer', 'description' => 'Id de la cita que se va a cancelar. Solo hace falta cuando la paciente tiene varias citas próximas; en ese caso la herramienta te devuelve los ids para que le preguntes cuál. Es un número interno: NO se lo menciones a la paciente.'],
                        'motivo' => ['type' => 'string', 'description' => 'Por qué cancela, en pocas palabras, si lo dijo. Queda anotado en la cita para la doctora.'],
                    ],
                    'required' => [],
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
                        'duracion_minutos' => ['type' => 'integer', 'description' => 'Duración en minutos. NO la inventes: omítela salvo que la doctora haya dicho expresamente cuánto dura ese procedimiento. Si se omite se usa la del servicio y, si el servicio no la trae, '.$this->duracionPorDefecto().' minutos, que es lo que dura una valoración.'],
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
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass, 'required' => []],
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
                'reagendar_cita' => $this->toolReschedule($input),
                'cancelar_cita' => $this->toolCancel($input),
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
        $duracion = max(15, (int) ($this->resolveService((string) ($input['servicio'] ?? ''))?->duration_minutes ?: $this->duracionPorDefecto()));

        // Si NINGÚN día del rango se atiende —todos cerrados a mano o fuera del
        // horario semanal— no hay nada que preguntarle a Google: se sale antes
        // de la llamada. Ahorra una petición y, sobre todo, hace que la
        // respuesta siga siendo correcta aunque el calendario no responda.
        $abiertos = 0;
        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i);
            if (! Settings::isClosedOn($d) && ($hours[$d->dayOfWeek] ?? null)) {
                $abiertos++;
            }
        }

        if ($abiertos === 0) {
            $cerrados = [];
            for ($i = 0; $i < $days; $i++) {
                $d = $start->copy()->addDays($i);
                $cerrados[] = ucfirst($d->locale('es')->isoFormat('dddd D [de] MMMM')).': cerrado.';
            }

            return "El consultorio no atiende ninguno de esos días:\n".implode("\n", $cerrados)
                ."\nOfrécele otra fecha; NO le ofrezcas horas de estos días.";
        }

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

            // Día cerrado a mano (festivo, vacaciones, un puente). Se comprueba
            // ANTES del horario semanal: da igual que sea un martes normal, ese
            // día no se atiende. El motivo NO se le cuenta a la paciente —a
            // nadie le importa por qué— y decirlo solo invita a negociar.
            if (Settings::isClosedOn($day)) {
                $lines[] = "{$label}: cerrado.";

                continue;
            }

            if (! $window) {
                $lines[] = "{$label}: cerrado.";

                continue;
            }

            $open = $day->copy()->setTimeFromTimeString($window[0]);
            $close = $day->copy()->setTimeFromTimeString($window[1]);

            // El descanso se trata como tiempo ocupado: no es un evento de
            // Google, pero para la agenda significa exactamente lo mismo. Así
            // reutiliza la comprobación de solape de abajo en vez de duplicarla.
            $ocupado = $busy;
            if ($descanso = $this->ventanaDelDia(Settings::scheduleBreaks(), $day)) {
                $ocupado[] = ['start' => $descanso[0], 'end' => $descanso[1]];
            }

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
                foreach ($ocupado as $b) {
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
     * Traduce una tabla `día de la semana => ['HH:MM','HH:MM']` a un par de
     * Carbon situado en el día concreto, o null si ese día no tiene ventana.
     *
     * @param  array<int,array{0:string,1:string}|null>  $tabla
     * @return array{0:Carbon,1:Carbon}|null
     */
    private function ventanaDelDia(array $tabla, Carbon $day): ?array
    {
        $w = $tabla[$day->dayOfWeek] ?? null;

        if (! is_array($w) || count($w) !== 2) {
            return null;
        }

        return [
            $day->copy()->setTimeFromTimeString($w[0]),
            $day->copy()->setTimeFromTimeString($w[1]),
        ];
    }

    /**
     * Motivo por el que una cita NO cabe en la agenda, o null si sí cabe.
     *
     * Se comprueba aquí además de al ofrecer horarios porque la paciente puede
     * pedir una hora que nunca se le ofreció —«¿y a las 12:30?»— y hasta ahora
     * `createBooking` solo miraba Google y los apartados: la agendaba igual,
     * en pleno almuerzo o incluso de madrugada.
     */
    private function motivoFueraDeHorario(Carbon $start, Carbon $end): ?string
    {
        $hora = $start->format('h:i a');
        $dia = $start->copy()->startOfDay();

        // Un día cerrado a mano tiene que bloquearse TAMBIÉN aquí, no solo al
        // ofrecer horarios: esta comprobación existe precisamente porque la
        // paciente puede pedir una hora que nunca se le ofreció («¿y el 25?»).
        // Sin esto, Lore le cobraría la valoración y agendaría en un día que la
        // doctora marcó como cerrado.
        if (Settings::isClosedOn($dia)) {
            $fecha = $start->locale('es')->isoFormat('dddd D [de] MMMM');

            return "ERROR: el consultorio no atiende el {$fecha}. No agendes ahí; ofrécele otro día.";
        }

        $atencion = $this->ventanaDelDia(Settings::scheduleHours(), $dia);
        if (! $atencion) {
            $nombre = $start->locale('es')->isoFormat('dddd');

            return "ERROR: el consultorio no atiende los {$nombre}. No agendes ahí; ofrécele un día en que sí se atienda.";
        }

        // El fin se compara contra el cierre: lo que importa es que quepa el
        // procedimiento entero, no solo que empiece dentro de la jornada.
        if ($start->lt($atencion[0]) || $end->gt($atencion[1])) {
            return "ERROR: las {$hora} quedan fuera del horario de atención de ese día ("
                .$atencion[0]->format('h:i a').' a '.$atencion[1]->format('h:i a')
                .'), contando lo que dura el procedimiento. No agendes ahí; ofrécele un horario dentro de la jornada.';
        }

        $descanso = $this->ventanaDelDia(Settings::scheduleBreaks(), $dia);
        if ($descanso && $start->lt($descanso[1]) && $end->gt($descanso[0])) {
            return "ERROR: las {$hora} caen en el descanso de la doctora ("
                .$descanso[0]->format('h:i a').' a '.$descanso[1]->format('h:i a')
                .'). No agendes ahí; ofrécele un horario antes o después del descanso.';
        }

        return null;
    }

    /**
     * Motivo por el que el hueco NO está libre, o null si sí lo está.
     *
     * Vive aparte porque lo necesitan DOS caminos —agendar y reprogramar— y la
     * regla de «no pisar a nadie» no puede estar escrita dos veces: la primera
     * versión de esto ya se pagó cara cuando ofrecer horarios y agendar no
     * usaban la misma duración.
     *
     * `$excepto` es la cita que se está moviendo. Su propio evento sigue en
     * Google mientras se recalcula, así que sin esta excepción una paciente no
     * podría correr su cita 15 minutos: se chocaría consigo misma. freeBusy no
     * devuelve ids, así que se reconoce por el hueco exacto que ocupa.
     */
    private function motivoOcupado(Carbon $start, Carbon $end, ?Appointment $excepto = null): ?string
    {
        $tz = Settings::googleTimezone();

        $busy = GoogleCalendarService::fromConfig()->busyTimes(
            $start->copy()->startOfDay()->toRfc3339String(),
            $start->copy()->endOfDay()->toRfc3339String(),
        );

        $suyo = $excepto
            ? [
                $this->enHoraLocal($excepto->starts_at, $tz)->format('Y-m-d H:i'),
                $this->enHoraLocal($excepto->ends_at, $tz)->format('Y-m-d H:i'),
            ]
            : null;

        foreach ($busy as $b) {
            $bs = Carbon::parse($b['start'])->tz($tz);
            $be = Carbon::parse($b['end'])->tz($tz);

            if ($suyo && [$bs->format('Y-m-d H:i'), $be->format('Y-m-d H:i')] === $suyo) {
                continue; // es la cita que estamos moviendo, no un estorbo
            }

            if ($start->lt($be) && $end->gt($bs)) {
                return "ERROR: el horario de las {$start->format('h:i a')} ya está ocupado. No agendes ahí; ofrece otro horario libre.";
            }
        }

        // Segunda red: el hueco puede estar apartado por otra paciente que está
        // pagando ahora mismo y cuyo pago todavía no llegó, así que en Google
        // aún no aparece nada.
        foreach (PaymentLink::heldSlots($this->user->id, $tz, $this->conversation?->id) as $reserva) {
            if ($start->lt($reserva['end']) && $end->gt($reserva['start'])) {
                return "ERROR: el horario de las {$start->format('h:i a')} está apartado por otra paciente que tiene un pago en curso. No agendes ahí; ofrécele otro horario libre.";
            }
        }

        return null;
    }

    /**
     * La misma fecha, leída como hora de pared del consultorio.
     *
     * Las citas se guardan sin offset (ver `Appointment::serializeDate`), así
     * que al releerlas Eloquent las etiqueta con la zona de la aplicación y no
     * con la de la clínica. Compararlas así contra `now()` o contra lo que
     * devuelve Google desplaza todo cinco horas sin que nada falle a la vista.
     */
    private function enHoraLocal(Carbon $fecha, string $tz): Carbon
    {
        return Carbon::parse($fecha->format('Y-m-d H:i:s'), $tz);
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
        $duracion = max(15, (int) ($input['duracion_minutos'] ?? 0)
            ?: ($this->resolveService($servicioPedido)?->duration_minutes ?? 0)
            ?: $this->duracionPorDefecto());

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
        $porTransferencia = (bool) ($input['pago_por_transferencia'] ?? false);

        // Transferencia: no hay nada que consultar, porque el abono aparece en
        // el banco de la doctora y no en un API. Se aparta el cupo igual —si no,
        // lo pierde mientras alguien verifica a mano— pero la cita queda MARCADA
        // y sale con el aviso en el calendario de la doctora.
        //
        // Es una concesión deliberada: aquí se agenda SIN comprobar el pago, que
        // es justo lo que la pasarela existe para evitar. Lo que la hace
        // aceptable es que el descubierto sea VISIBLE (marca en el evento y en
        // el resumen diario), no que sea improbable.
        if ($porTransferencia) {
            $resultado = $this->createBooking($input + ['transferencia_por_verificar' => true]);

            if (! $resultado['appointment']) {
                return $resultado['message'];
            }

            // Los datos de pago viajan AQUÍ, en el resultado de la herramienta,
            // y no solo como regla del system prompt: ahí competían con otras
            // instrucciones obligatorias del mismo mensaje (recordatorios,
            // política de cancelación) y el modelo los omitía una y otra vez,
            // dejando a la paciente con la cita apartada y sin saber a dónde
            // transferir. En el tool result los tiene delante al escribir.
            $datos = trim((string) (Settings::botConfig()['clinic_payment'] ?? ''));

            // Red de seguridad: si el modelo no los escribe, los anexa el código.
            $this->datosPagoPorAnexar = $datos ?: null;

            return $resultado['message']."\n\nLa cita quedó apartada pero el pago NO está verificado."
                .(filled($datos)
                    ? "\n\nCOPIA ESTOS DATOS DE PAGO EN TU RESPUESTA, TAL CUAL, SIN RESUMIRLOS NI CAMBIAR UN SOLO NÚMERO. Es lo más importante del mensaje: sin ellos no tiene a dónde transferir.\n\n{$datos}"
                    : "\n\nNO hay datos de transferencia configurados: dile que el consultorio se los envía enseguida y escala con escalar_a_humano.")
                ."\n\nDespués de los datos, confírmale la cita con calidez y dile que el consultorio verifica el pago antes de la cita.";
        }

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

        // Un 0 explícito del modelo cuenta como «no lo sé», igual que en
        // `toolBook`: antes se colaba tal cual y creaba una cita de duración
        // cero, que en Google no bloquea nada y deja el hueco vendible.
        $duration = max(15, (int) ($input['duracion_minutos'] ?? 0)
            ?: ($service?->duration_minutes ?? 0)
            ?: $this->duracionPorDefecto());
        $end = $start->copy()->addMinutes($duration);

        // Primero la agenda de la doctora: día cerrado, fuera de jornada o en
        // pleno descanso. Va ANTES de consultar Google porque no hace falta
        // preguntar por huecos en una hora en la que no se atiende.
        if ($fueraDeHorario = $this->motivoFueraDeHorario($start, $end)) {
            return ['message' => $fueraDeHorario, 'appointment' => null];
        }

        // Re-verifica que no se solape con algo ya ocupado ni con un hueco que
        // otra paciente tenga apartado mientras paga.
        if ($ocupado = $this->motivoOcupado($start, $end)) {
            return ['message' => $ocupado, 'appointment' => null];
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
            // Sella DESDE CUÁNDO espera verificación: el resumen diario lo usa
            // para avisar de las que llevan demasiado sin confirmar.
            'transfer_pending_at' => ($input['transferencia_por_verificar'] ?? false) ? now() : null,
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
     * Mueve a otro día u hora una cita que la paciente ya tenía.
     *
     * No vuelve a cobrar: la valoración ya está pagada y lo que cambia es el
     * cupo, no el trato. Sí revalida la agenda ENTERA —día cerrado, jornada,
     * descanso, huecos ocupados y apartados—, porque una hora que la paciente
     * propone de memoria («muévela para las 12:30») no pasó nunca por
     * `consultar_disponibilidad`.
     *
     * @param  array<string,mixed>  $input
     */
    private function toolReschedule(array $input): string
    {
        $cita = $this->citaObjetivo($input['cita_id'] ?? null, 'mover');

        if (is_string($cita)) {
            return $cita;
        }

        $tz = Settings::googleTimezone();
        $now = Carbon::now($tz);

        $antes = $this->enHoraLocal($cita->starts_at, $tz);
        // La duración se conserva: es la del procedimiento que ya acordaron, y
        // recalcularla por el nombre del servicio arriesga cambiarla sin que
        // nadie lo haya pedido.
        $duracion = max(15, (int) abs($antes->diffInMinutes($this->enHoraLocal($cita->ends_at, $tz))));

        $start = Carbon::parse($input['fecha_hora'], $tz);
        $end = $start->copy()->addMinutes($duracion);

        if ($start->lte($now)) {
            return 'ERROR: esa fecha y hora ya pasaron, así que no se puede mover ahí. Consulta consultar_disponibilidad y ofrécele horarios futuros.';
        }

        if ($fueraDeHorario = $this->motivoFueraDeHorario($start, $end)) {
            return $fueraDeHorario;
        }

        if ($ocupado = $this->motivoOcupado($start, $end, $cita)) {
            return $ocupado;
        }

        $motivo = trim((string) ($input['motivo'] ?? ''));
        $nota = 'Reprogramada por la asistente el '.$now->format('d/m/Y H:i').': estaba el '
            .$antes->format('d/m/Y h:i a').'.'.($motivo !== '' ? " Motivo: {$motivo}." : '');
        $notas = trim((string) ($cita->notes ?? ''));

        $cita->forceFill([
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $end->format('Y-m-d H:i:s'),
            // Las marcas de recordatorio se refieren a la fecha VIEJA. Sin
            // limpiarlas, a quien ya recibió el aviso de 24 h no le llega
            // NINGUNO para la fecha nueva y se queda con el recordatorio viejo
            // como última información. Es el mismo cuidado que tiene el panel
            // al mover una cita a mano (ver AppointmentController::update).
            'reminder_24h_sent_at' => null,
            'reminder_2h_sent_at' => null,
            'notes' => mb_substr(trim($notas !== '' ? $notas."\n".$nota : $nota), 0, 2000),
        ])->save();

        $avisoAgenda = '';

        try {
            $google = GoogleCalendarService::fromConfig();

            // Si el evento nunca llegó a crearse (una sincronización que falló
            // en su día) se crea ahora: la cita existe y tiene que verse.
            if (filled($cita->google_event_id)) {
                $google->updateEvent($cita);
            } else {
                $cita->google_event_id = $google->createEvent($cita);
            }

            $cita->forceFill(['google_synced_at' => now(), 'google_sync_error' => null])->save();
        } catch (Throwable $e) {
            $cita->forceFill(['google_sync_error' => $e->getMessage()])->save();
            $avisoAgenda = ' El cambio quedó guardado, pero el calendario de la doctora no se pudo actualizar ('
                .$e->getMessage().'): confírmale igual la fecha nueva a la paciente.';
        }

        Log::info('El asistente reprogramó una cita', [
            'appointment_id' => $cita->id,
            'conversation_id' => $this->conversation?->id,
            'antes' => $antes->format('Y-m-d H:i'),
            'ahora' => $start->format('Y-m-d H:i'),
        ]);

        // El plazo de 24 h se cuenta contra la hora de la cita VIEJA, que es la
        // que se estaba incumpliendo. La cita se mueve igual —la doctora lo
        // prefiere a perder a la paciente—, pero tiene que quedarle claro que
        // el plazo existe, o la próxima vez lo descubre perdiendo el dinero.
        $politica = $antes->lte($now->copy()->addDay())
            ? "\n\nOJO: faltaban menos de 24 horas para la cita original. Se movió igual, pero díselo con calidez en este mismo mensaje: "
                .'los cambios o cancelaciones con menos de 24 horas de anticipación no dan derecho a la devolución del valor de la valoración, '
                .'así que si tampoco puede con la fecha nueva te avise con más tiempo. No la regañes ni se lo cobres: es información, no una advertencia.'
            : '';

        $cuando = $start->locale('es')->isoFormat('dddd D [de] MMMM').' a las '.$start->locale('es')->isoFormat('h:mm a');

        return 'Listo: la cita de '.$cita->patient_name.' se movió del '
            .$antes->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a').' al '.$cuando.'.'
            .$avisoAgenda
            ."\n\nNO tiene que volver a pagar: la valoración que ya pagó pasa a la fecha nueva. Díselo, que es lo primero que teme."
            ."\n\nConfírmale la nueva fecha y hora con calidez, recuérdale la dirección de la clínica y avísale que los recordatorios le llegarán para la fecha nueva."
            .$politica;
    }

    /**
     * Cancela una cita que la paciente ya tenía.
     *
     * Solo mueve la cita y el calendario. El dinero NO se toca desde aquí: la
     * devolución la hace una persona, y por eso el resultado le dice a Lore si
     * corresponde y que escale — decirle a la paciente «ya te lo devolvimos»
     * sería mentirle sobre plata suya.
     *
     * @param  array<string,mixed>  $input
     */
    private function toolCancel(array $input): string
    {
        $cita = $this->citaObjetivo($input['cita_id'] ?? null, 'cancelar');

        if (is_string($cita)) {
            return $cita;
        }

        $tz = Settings::googleTimezone();
        $now = Carbon::now($tz);
        $cuando = $this->enHoraLocal($cita->starts_at, $tz);

        $motivo = trim((string) ($input['motivo'] ?? ''));
        $nota = 'Cancelada por la asistente el '.$now->format('d/m/Y H:i').'.'
            .($motivo !== '' ? " Motivo: {$motivo}." : '');
        $notas = trim((string) ($cita->notes ?? ''));

        $avisoAgenda = '';
        $eventoBorrado = ! filled($cita->google_event_id);

        // El evento sale del calendario para que el hueco vuelva a venderse. Si
        // Google falla, la cita se cancela igual: dejarla como «agendada» sería
        // peor —le seguirían llegando recordatorios de una cita que ella misma
        // canceló—, y el hueco lo libera la doctora a mano.
        if (filled($cita->google_event_id)) {
            try {
                GoogleCalendarService::fromConfig()->deleteEvent($cita->google_event_id);
                $eventoBorrado = true;
            } catch (Throwable $e) {
                $avisoAgenda = ' El evento no se pudo quitar del calendario de la doctora ('
                    .$e->getMessage().'), pero la cita SÍ quedó cancelada.';
            }
        }

        // `status = cancelled` es lo que apaga los recordatorios: el comando
        // solo escribe a las citas en «scheduled» o «confirmed».
        $cita->forceFill([
            'status' => 'cancelled',
            'google_event_id' => $eventoBorrado ? null : $cita->google_event_id,
            'google_synced_at' => $eventoBorrado ? now() : $cita->google_synced_at,
            'reminder_24h_sent_at' => null,
            'reminder_2h_sent_at' => null,
            'notes' => mb_substr(trim($notas !== '' ? $notas."\n".$nota : $nota), 0, 2000),
        ])->save();

        Log::info('El asistente canceló una cita', [
            'appointment_id' => $cita->id,
            'conversation_id' => $this->conversation?->id,
            'era_para' => $cuando->format('Y-m-d H:i'),
        ]);

        $aTiempo = $cuando->gt($now->copy()->addDay());

        $devolucion = $aTiempo
            ? 'Avisó con MÁS de 24 horas de anticipación, así que SÍ le corresponde la devolución del valor de la valoración. '
                .'Díselo, y escala con escalar_a_humano en este mismo turno para que una persona del consultorio le haga el reembolso. '
                .'NO le digas que ya está devuelto ni le prometas una fecha: tú no tramitas el dinero.'
            : 'Avisó con MENOS de 24 horas de anticipación, así que según la política NO se le devuelve el valor de la valoración. '
                .'Explícaselo con calidez y sin dramatizar, una sola vez, y no la hagas sentir mal.';

        return 'Listo: la cita de '.$cita->patient_name.' del '
            .$cuando->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a')
            .' quedó CANCELADA y ya no recibirá recordatorios de ella.'.$avisoAgenda
            ."\n\n".$devolucion
            ."\n\nDespídete con calidez y déjale la puerta abierta para agendar cuando quiera.";
    }

    /**
     * La cita sobre la que Lore va a actuar, o el texto que debe leer si no hay
     * una sola candidata clara.
     *
     * Devolver el error como string y no lanzar es deliberado: lo que Lore
     * necesita no es una excepción, es una instrucción de qué decirle a la
     * paciente. Y sobre todo, JAMÁS puede tocar la cita de otra: si no se logra
     * identificar la suya, aquí no se mueve nada.
     */
    private function citaObjetivo(mixed $id, string $verbo): Appointment|string
    {
        $citas = $this->citasProximas();

        if ($citas->isEmpty()) {
            return "ERROR: no encuentro ninguna cita próxima a nombre de esta paciente, así que no hay nada que {$verbo}. "
                .'NO le digas que se la moviste ni que se la cancelaste: no sería cierto. '
                .'Pregúntale con calidez a nombre de quién y para qué día quedó, y si insiste en que sí la tiene, escala con escalar_a_humano.';
        }

        $id = (int) $id;

        if ($id > 0) {
            return $citas->firstWhere('id', $id)
                ?: 'ERROR: esa cita no es de esta paciente o ya no está activa, así que no se tocó nada. '.$this->listaDeCitas($citas);
        }

        if ($citas->count() > 1) {
            return "Esta paciente tiene más de una cita próxima y no se movió ninguna. Pregúntale CUÁL quiere {$verbo} "
                .'y vuelve a llamar la herramienta con el cita_id que corresponda. '.$this->listaDeCitas($citas);
        }

        return $citas->first();
    }

    /**
     * Las citas futuras de la paciente con la que se está conversando.
     *
     * Se filtra en PHP y no en SQL a propósito: el teléfono está guardado de
     * cualquier forma (con 57 y sin él, con espacios), y la comparación buena
     * es la que ya usa el resto del sistema. Son las citas FUTURAS de una
     * clínica, así que la lista es corta.
     *
     * @return Collection<int,Appointment>
     */
    private function citasProximas(): Collection
    {
        $lead = $this->conversation?->lead;
        $telefono = Settings::phoneWithCountryCode($lead?->phone);

        // Sin lead ni teléfono no hay forma de saber cuál es «su» cita, y
        // adivinar por el nombre movería la de otra paciente que se llame igual.
        if (! $lead && blank($telefono)) {
            return collect();
        }

        return $this->user->appointments()
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->where('starts_at', '>=', Carbon::now(Settings::googleTimezone())->format('Y-m-d H:i:s'))
            ->orderBy('starts_at')
            ->with('service')
            ->get()
            ->filter(fn (Appointment $c) => ($lead && $c->lead_id === $lead->id)
                || ($telefono && Settings::phoneWithCountryCode($c->patient_phone) === $telefono))
            ->values();
    }

    /**
     * Listado de citas para que Lore sepa de cuáles habla y con qué id.
     *
     * @param  Collection<int,Appointment>  $citas
     */
    private function listaDeCitas(Collection $citas): string
    {
        $tz = Settings::googleTimezone();

        $lineas = $citas->map(function (Appointment $c) use ($tz) {
            $cuando = $this->enHoraLocal($c->starts_at, $tz)->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a');

            return "cita_id {$c->id}: {$cuando}".($c->service?->name ? " ({$c->service->name})" : '');
        })->implode('; ');

        return "Sus citas próximas son — {$lineas}. Los cita_id son internos: nunca se los menciones a la paciente.";
    }

    /**
     * Lo que Lore debe saber de entrada sobre las citas de esta paciente.
     *
     * Va en el prompt y no solo en las herramientas porque el problema aparece
     * antes de llamarlas: sin esto, a un «necesito cambiar mi cita» Lore
     * responde preguntando cuándo la tiene, que es un dato que el consultorio
     * ya tiene y que la paciente no siempre recuerda.
     */
    private function citasPrompt(): string
    {
        if (! $this->canSchedule()) {
            return '';
        }

        $citas = $this->citasProximas();

        if ($citas->isEmpty()) {
            return '';
        }

        return '- Esta paciente YA tiene cita agendada. '.$this->listaDeCitas($citas)
            .' Cuando hable de «mi cita» es esa: no le preguntes cuándo la tiene, ya lo sabes. '
            .'Puedes moverla con reagendar_cita o cancelarla con cancelar_cita.';
    }

    /**
     * Encuentra el servicio del catálogo que mejor corresponde al texto que dio
     * el paciente. Es tolerante: admite frases ("quiero botox para la frente"),
     * nombres comerciales y busca también en la descripción/contexto del servicio.
     */
    /**
     * Quita tildes y diéresis para poder comparar.
     *
     * El catálogo está escrito con la ortografía correcta («Depilación Láser
     * Diodo») y de fuera llega casi siempre sin tildes: los slugs de la web
     * («/servicios/depilacion-laser-diodo/») y lo que la paciente teclea en el
     * chat. Sin esto «depilacion laser diodo» no reconocía su propio servicio y
     * el puntaje se lo llevaba cualquier otro que tuviera una palabra suelta.
     */
    private static function sinTildes(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
        ]);
    }

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

        $q = self::sinTildes(Str::lower($query));

        // 1) Coincidencia directa: el nombre contiene la frase, o la frase el nombre.
        foreach ($services as $s) {
            $name = self::sinTildes(Str::lower($s->name));
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
            ->map(fn ($w) => preg_replace('/[^a-zñ0-9]/u', '', (string) $w))
            ->filter(fn ($w) => strlen((string) $w) >= 4 && ! in_array($w, $stop, true))
            ->unique()
            ->values();

        if ($words->isEmpty()) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($services as $s) {
            $name = self::sinTildes(Str::lower($s->name));
            $haystack = self::sinTildes(Str::lower($s->name.' '.$s->short_description.' '.$s->ai_context));

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
            ? '- Para pagar la valoración ofrécele las DOS opciones y deja que elija: el link de pago en línea (el más cómodo, porque confirma solo) o transferencia/Nequi.'
                ."\n- En cuanto elija transferencia o Nequi, escríbele los datos COMPLETOS de una vez. No esperes a que te los pida por segunda vez ni le digas «recuerda hacer la transferencia» sin ponerle a dónde: eso la deja atascada."
                ."\n- Haber NOMBRADO la transferencia como opción no es haber enviado los datos: los datos son el banco, el número, el titular y el Nequi. Si no los has escrito con sus números, todavía no se los has dado."
                ."\n- El link de pago NO es una forma de pago genérica: es EXCLUSIVAMENTE para pagar la valoración. Nunca lo ofrezcas para pagar tratamientos u otros servicios."
            : '- Para pagar la valoración comparte los datos de transferencia, consignación o Nequi que aparecen arriba, y pídele que te AVISE por este chat cuando lo haya hecho.'
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

        // Las citas que la paciente ya tiene: van con la presentación, al final
        // y por el mismo motivo (cambian en cada conversación y a cada rato).
        $citasBlock = $this->citasPrompt();

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

        # Longitud de tus mensajes
        Esto es WhatsApp: un mensaje que se lee como un folleto se salta. Pero quedarse corto con quien pregunta en serio es PEOR, y es el error que hay que evitar aquí: la doctora escribió esa información completa por algo.
        - Cuando el paciente PREGUNTE POR UN PROCEDIMIENTO, cuéntale lo que la base de conocimiento diga de él: en qué consiste, cómo es, cuánto dura, la recuperación y lo que la doctora haya dejado escrito. NO lo resumas en dos frases ni lo dejes "para la valoración": eso es justo lo que vino a saber.
        - Usa el contenido de la base de conocimiento, no una versión abreviada tuya. Si la doctora escribió los detalles, dáselos.
        - Para lo demás —saludos, coordinar un horario, confirmar datos— sé breve: 3 o 4 líneas bastan.
        - Termina con UNA sola pregunta, no con dos o tres opciones encadenadas.
        - No repitas lo que el paciente acaba de decirte ni resumas lo que ya le dijiste antes.
        - Cuando compartas datos de pago, un link o los horarios disponibles, cópialos completos aunque ocupen más.

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
        - SIEMPRE que hables del valor de la valoración —su precio, el anticipo o lo que se paga el día de la cita— aclárale en ESE MISMO mensaje que ese valor se le ABONA al costo del procedimiento o tratamiento que decida realizar. No es un cobro aparte que se pierde: se le descuenta después. Es lo primero que cambia la decisión de la paciente y se le olvida preguntarlo, así que no esperes a que lo pregunte.
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
        - Si el paciente pregunta por un servicio que TIENE material visual, MÁNDALO en ese mismo mensaje, junto a tu explicación. No hace falta que lo pida.
        - NUNCA preguntes "¿quieres que te comparta unas fotos?" ni "¿te gustaría ver imágenes?". Preguntarlo obliga al paciente a un turno más para algo que ya quería: envíalas directamente.
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
        {$citasBlock}
        {$campaignBlock}
        PROMPT;
    }

    /**
     * Página del sitio web desde la que escribió la paciente, con el servicio
     * que le corresponde si se puede reconocer.
     *
     * El botón de WhatsApp de la web prellena el mensaje con la URL de la
     * página que estaba viendo: «¡Hola! Vi en Google
     * https://drajasminblanco.com/servicios/implante-capilar-en-bogota/ y
     * quiero más información». Ahí viene escrito a qué vino.
     *
     * Meta NO manda `referral` en este caso —eso es exclusivo de los anuncios
     * Click-to-WhatsApp—, así que hasta ahora el dato se perdía: el bot abría
     * con «¿sobre qué tratamiento te gustaría saber?» a alguien que ya lo
     * había dicho, y esas conversaciones se caían ahí mismo.
     *
     * @return array{url:string, slug:string, service:?Service}|null
     */
    private function paginaDeOrigen(): ?array
    {
        if ($this->paginaDeOrigen !== false) {
            return $this->paginaDeOrigen;
        }

        $this->paginaDeOrigen = null;

        // El primero SUYO: la URL viaja en el mensaje prellenado con el que se
        // abre el chat, no en lo que conteste después.
        $primero = $this->conversation?->messages()
            ->where('role', 'user')
            ->orderBy('id')
            ->value('content');

        if (blank($primero)) {
            return null;
        }

        $dominio = preg_quote(Settings::websiteDomain(), '#');

        // El protocolo es opcional a propósito: hoy la web lo prellena con
        // `https://`, pero un «vi en www.dominio.com/x» pegado a mano trae la
        // misma información y no hay motivo para tirarla.
        if (! preg_match('#(?:https?://)?(?:www\.)?'.$dominio.'(/[^\s<>"\']*)?#i', (string) $primero, $m)) {
            return null;
        }

        // De «/servicios/implante-capilar-en-bogota/» sale «implante capilar en
        // bogota», que es justo lo que `resolveService()` sabe puntuar. Se
        // ignoran los segmentos genéricos del camino («servicios», «blog») para
        // que no compitan con el nombre real del tratamiento.
        $ruta = trim((string) ($m[1] ?? ''), '/');
        $segmentos = array_values(array_filter(
            explode('/', $ruta),
            fn (string $s) => $s !== '' && ! in_array(Str::lower($s), ['servicios', 'servicio', 'tratamientos', 'tratamiento', 'blog', 'es'], true),
        ));

        $slug = $segmentos === [] ? '' : str_replace('-', ' ', Str::lower(end($segmentos)));

        $this->paginaDeOrigen = [
            'url' => $m[0],
            'slug' => $slug,
            'service' => $slug === '' ? null : $this->resolveService($slug),
        ];

        return $this->paginaDeOrigen;
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

        $web = $this->paginaDeOrigen();

        if (! $campaign && $anuncio === '' && ! $web) {
            return '';
        }

        $lines = ['# De dónde viene este paciente'];

        // Quien llega del sitio web no trae `referral` —eso solo lo manda Meta
        // en los anuncios Click-to-WhatsApp—, pero sí trae la URL de la página
        // que estaba leyendo dentro del propio mensaje. Solo se usa cuando NO
        // hubo anuncio: si vino de uno, el texto del anuncio es mejor contexto.
        if ($web && $anuncio === '') {
            if ($web['service']) {
                $lines[] = "Escribió desde la página de «{$web['service']->name}» del sitio web, casi siempre tras buscar en Google. "
                    .'Da por hecho que viene por ESE tratamiento: háblale de él de entrada —en qué consiste, qué resuelve, cómo es el proceso— '
                    .'en vez de preguntarle en qué puedes ayudarle. Preguntárselo a alguien que ya dijo a qué venía es justo lo que hace que se caiga la conversación.';
            } elseif ($web['slug'] !== '') {
                $lines[] = "Escribió desde la página «{$web['slug']}» del sitio web, casi siempre tras buscar en Google. "
                    .'Ese es el tema que le interesa: entra por ahí en vez de preguntarle en qué puedes ayudarle.';
            } else {
                $lines[] = 'Escribió desde la portada del sitio web, sin entrar a ningún tratamiento concreto. '
                    .'Ahí sí toca preguntarle qué busca, pero con una sola pregunta corta y cálida.';
            }

            $lines[] = 'NO menciones la página ni des a entender que sabes lo que estaba viendo. Que se note en que aciertas con el tema, no en que lo dices.';
        }

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

        # Las dos formas de pagar la valoración
        - Cuando le pidas el pago, ofrécele SIEMPRE las dos opciones en el mismo mensaje, en este orden: (1) el link de pago, y (2) transferencia o Nequi. Que elija ella.
        - Del link: aclárale que NO necesita tener cuenta de Mercado Pago. Desde ahí puede pagar con tarjeta débito o crédito, con PSE entrando a su propio banco, o en efectivo en Efecty. Es la opción que confirma sola y le aparta el cupo al instante.
        - Si elige transferencia o Nequi: agenda con agendar_cita poniendo "pago_por_transferencia" en true, y en el MISMO mensaje ESCRIBE LOS DATOS DE PAGO COMPLETOS (banco, número de cuenta, titular, NIT y Nequi), copiados exactamente como aparecen arriba. Es obligatorio y es lo PRIMERO que va en el mensaje, antes de confirmarle la cita: sin esos datos no tiene a dónde transferir, y decirle "recuerda hacer la transferencia" sin ellos no le sirve de nada.
        - Con transferencia, confírmale la cita con normalidad y sin sembrarle desconfianza. Basta con que le digas que el consultorio verifica el pago antes de la cita y que le avise por aquí cuando la haya hecho. NO le pidas comprobante ni captura.
        - "pago_por_transferencia" en true SOLO si de verdad eligió ese medio. Nunca por insistencia, prisa, ni porque prometa pagar después: para todo lo demás sigue valiendo que sin pago confirmado no hay cita.

        # Política de cancelación
        - Si avisa con MÁS de 24 horas de anticipación respecto a la hora de su cita, SÍ se le devuelve el valor de la valoración.
        - Si avisa cuando faltan menos de 24 horas, o no se presenta, PIERDE el valor de la valoración: no se le devuelve, no le queda a favor y no se le abona a otra fecha.
        - Las 24 horas se cuentan contra la HORA de la cita, no contra el día: para una cita el jueves a las 3:00 p. m., el plazo se cierra el miércoles a las 3:00 p. m. Si te preguntan si están a tiempo, cuéntalo con la fecha y hora reales de su cita.
        - Dilo AL CONFIRMAR la cita, en una sola frase, con calidez y sin sonar a advertencia, junto al aviso de los recordatorios. Es obligatorio: tiene que quedarle claro ANTES de que le afecte, no cuando ya la incumplió, porque es dinero que ya pagó.
        - Si pregunta por la política, explícasela con claridad y sin dramatizar.
        - Puedes confirmarle si le corresponde o no la devolución según el plazo —eso es informarla, no tramitarla—, pero el DINERO lo mueve una persona, nunca tú.

        # Mover o cancelar una cita ya agendada (tienes herramientas para las dos cosas)
        - Si quiere CAMBIAR el día o la hora de una cita que ya tiene, muévela tú con reagendar_cita. Es lo que hay que ofrecerle siempre primero: casi nadie quiere cancelar, quiere otro día.
        - Dile SIEMPRE, al mover la cita, que NO tiene que volver a pagar: la valoración que ya pagó pasa a la fecha nueva. Es lo primero que teme y por lo que muchas ni preguntan.
        - Antes de moverla, comprueba la hora nueva con consultar_disponibilidad. La herramienta rechaza cualquier hora ocupada, fuera de la jornada, en el descanso o en un día cerrado.
        - Si la paciente YA te dijo cuándo la quiere —una fecha y hora concretas, o algo que las señala sin ambigüedad como "el próximo jueves a la misma hora"— y esa hora está libre, MUÉVELA con reagendar_cita en ese mismo turno y solo entonces cuéntaselo, ya hecho. NO le preguntes "¿te confirmo el cambio?": ya te lo pidió una vez, y si no vuelve a contestar se queda con la cita vieja creyendo que la cambiaste. Eso ya le pasó a una paciente.
        - Preguntarle antes de mover solo tiene sentido en dos casos: cuando no dijo cuándo la quiere (ahí sí, ofrécele horas reales y espera a que elija) o cuando la hora que pidió no está libre (ahí explícaselo y ofrécele las más cercanas).
        - Usa cancelar_cita SOLO si dice con claridad que no va a asistir y no quiere otra fecha. Antes de cancelar, ofrécele UNA vez moverla; si insiste en cancelar, hazlo sin insistir más.
        - No le confirmes el cambio ni la cancelación hasta que la herramienta te lo confirme. Si te devuelve un error, NO digas que quedó hecho: cuéntale lo que pasa y ofrécele otra opción.
        - Si la herramienta te dice que tiene varias citas próximas, pregúntale cuál quiere mover o cancelar y vuelve a llamarla con el cita_id. Los cita_id son internos: NUNCA se los menciones a la paciente, háblale de la fecha y la hora.
        - Las DEVOLUCIONES no las haces tú: cuando le corresponda que le devuelvan el valor de la valoración, díselo y escala con escalar_a_humano para que una persona la tramite. Nunca le digas que ya está devuelta ni le prometas una fecha de reembolso.
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
                $parts = ["## {$s->name}".($s->category ? " ({$s->category})" : '')];
                if (filled($s->price)) {
                    $parts[] = 'Precio referencial: $'.number_format((float) $s->price, 0, ',', '.').' COP';
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
                        $bits[] = $photos.' foto'.($photos > 1 ? 's' : '');
                    }
                    if ($videos) {
                        $bits[] = $videos.' video'.($videos > 1 ? 's' : '');
                    }
                    $parts[] = 'Material visual disponible ('.implode(' y ', $bits)
                        ."). Para enviarlo usa la etiqueta [[media:{$s->slug}]].";
                }

                return implode("\n", $parts);
            });
            $sections[] = "### SERVICIOS\n".$lines->implode("\n\n");
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
