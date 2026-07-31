<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\User;
use App\Services\BotService;
use App\Services\MetaAdsService;
use App\Services\WhatsAppService;
use App\Support\PatientLeads;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Procesa un mensaje entrante de WhatsApp: resuelve el paciente (lead), guarda
 * el historial de la conversación, genera la respuesta con el BotService
 * (Claude) y la envía de vuelta por la WhatsApp Cloud API.
 */
class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Techo de duración del job, en segundos.
     *
     * Responder un WhatsApp tarda 3-15 s normalmente, pero el ciclo de
     * herramientas puede encadenar hasta 6 llamadas a Claude (120 s de timeout
     * cada una) más Google Calendar y Mercado Pago: sin techo, un job colgado
     * puede correr varios minutos. Cuatro minutos es holgado para lo normal y
     * corta lo que evidentemente se atascó.
     *
     * Debe quedar POR DEBAJO del `retry_after` de la cola (300 s), o la cola
     * daría el job por abandonado mientras todavía corre. El servidor tiene
     * `pcntl`, así que este límite se aplica de verdad.
     *
     * NO se sube `tries`: este job manda el WhatsApp ANTES de guardarlo, así
     * que un reintento le enviaría a la paciente una segunda respuesta.
     */
    public int $timeout = 240;

    /**
     * @param  array<string,mixed>|null  $referral  Datos del anuncio Click-to-WhatsApp.
     * @param  array<string,mixed>|null  $media  Descriptor del adjunto (kind, id, mime…).
     */
    public function __construct(
        public readonly string $from,
        public readonly string $text,
        public readonly ?string $profileName = null,
        public readonly ?array $referral = null,
        public readonly ?array $media = null,
    ) {
    }

    public function handle(): void
    {
        // Se construye desde config (token + phone_id de las variables WHATSAPP_*).
        // NO por inyección: sin un binding, el contenedor lo crearía con token/
        // phone_id en null → isConfigured()=false → nunca respondería.
        $whatsapp = WhatsAppService::fromConfig();

        try {
            if (! $whatsapp->isConfigured()) {
                Log::warning('WhatsApp recibió un mensaje pero no está configurado para responder.');

                return;
            }

            // Clínica de un solo dueño: la doctora es el primer usuario.
            $doctor = User::query()->orderBy('id')->first();
            if (! $doctor) {
                Log::error('No hay ningún usuario (doctora) para atender el WhatsApp.');

                return;
            }

            // Por ahora solo entendemos texto. Otros formatos reciben un aviso
            // amable, pero SOLO si el bot está encendido y el número puede ser
            // atendido: este aviso también es Lore hablando, y con el bot en
            // pausa (o en modo prueba) nadie debe recibir nada automático.
            if (trim($this->text) === '') {
                if (Settings::whatsappBotEnabled() && ! $this->fueraDeLaListaDePrueba()) {
                    $whatsapp->sendText(
                        $this->from,
                        'Por ahora solo puedo leer mensajes de texto 😊 Cuéntame en qué te puedo ayudar.',
                    );
                }

                return;
            }

            // Campaña de origen (si vino de un anuncio Click-to-WhatsApp).
            $campaign = $this->resolveCampaign($doctor);

            // Lead por teléfono (lo crea si es la primera vez que escribe). Si
            // la paciente ya estaba en el pipeline por la agenda —sin teléfono,
            // porque el calendario solo trae el nombre— se le completa el número
            // en vez de crear un duplicado.
            $lead = PatientLeads::resolve($doctor, $this->profileName, $this->from, [
                'channel' => 'whatsapp',
                'source' => $this->referral['headline'] ?? 'whatsapp',
                'last_contact_at' => now(),
            ]);

            // Completa el nombre real si Meta lo trae y antes no lo teníamos.
            if (filled($this->profileName) && ($lead->name === $this->from || blank($lead->name))) {
                $lead->name = $this->profileName;
            }
            // Atribución: el lead conserva la primera campaña que lo trajo.
            if ($campaign && blank($lead->campaign_id)) {
                $lead->campaign_id = $campaign->id;
            }
            $lead->last_contact_at = now();
            $lead->save();

            // Una conversación viva por paciente en el canal de WhatsApp.
            $conversation = $doctor->conversations()->firstOrCreate(
                ['lead_id' => $lead->id, 'channel' => 'whatsapp'],
                ['title' => 'WhatsApp · '.($lead->name ?: $this->from)],
            );

            // Si llegó un referral nuevo, actualiza la campaña de la conversación.
            if ($campaign) {
                $conversation->campaign_id = $campaign->id;
                $conversation->referral = $this->referral;
                $conversation->save();
            }

            // El archivo se guarda ANTES de cualquier interruptor: que el bot
            // esté en pausa no debe costarle a la doctora la foto que le mandó
            // la paciente.
            $conversation->messages()->create([
                'role' => 'user',
                'content' => $this->text,
                'media' => $this->guardarAdjunto($whatsapp, $conversation) ?: null,
            ]);

            // Interruptor general: con el bot apagado el mensaje queda guardado y
            // visible en la bandeja, pero no se le responde a nadie. Sirve para
            // conectar el webhook sin que Lore empiece a escribirle a pacientes
            // reales, y como botón de pánico si algo se tuerce.
            if (! Settings::whatsappBotEnabled()) {
                Log::info('Mensaje de WhatsApp recibido con el bot apagado; no se responde.', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            // Modo prueba: con la lista blanca cargada, Lore SOLO le responde a
            // esos números. Permite encender el bot y probar el canal en vivo
            // sin que le conteste a pacientes reales; el mensaje de las demás
            // se guarda igual y aparece en la bandeja. Lista vacía = normal.
            if ($this->fueraDeLaListaDePrueba()) {
                Log::info('Modo prueba activo: el número no está en la lista blanca, no se responde.', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            // La doctora tomó el control de este chat: el mensaje queda guardado
            // y visible en la bandeja, pero el asistente no contesta.
            if (! $conversation->bot_enabled) {
                return;
            }

            $bot = BotService::fromUser($doctor);
            if (! $bot->isReady()) {
                Log::warning('La IA no está configurada (falta ANTHROPIC_API_KEY); no se responde el WhatsApp.');

                return;
            }

            // Contexto de campaña para el bot: el del anuncio recién llegado o,
            // si no, el que ya quedó asociado a esta conversación.
            $campaignForBot = $campaign
                ?? ($conversation->campaign_id ? $conversation->campaign : null);

            $result = $bot->reply($conversation, $campaignForBot);

            // Texto primero…
            if (trim($result['text']) !== '') {
                $whatsapp->sendText($this->from, $result['text']);
            }

            // …y luego las fotos/videos que el bot decidió enviar.
            foreach ($result['media'] as $item) {
                if (blank($item['url'] ?? null)) {
                    continue;
                }
                $whatsapp->sendMedia(
                    $this->from,
                    $item['type'] ?? 'image',
                    $item['url'],
                    $item['caption'] ?? '',
                );
            }

            $conversation->messages()->create([
                'role' => 'assistant',
                'sent_by' => 'bot',
                'content' => $result['text'],
                'media' => $result['media'] ?: null,
            ]);
        } catch (Throwable $e) {
            Log::error('Falló el procesamiento de un mensaje de WhatsApp', [
                'from' => $this->from,
                'error' => $e->getMessage(),
            ]);

            // Aviso de cortesía para que el paciente no quede sin respuesta.
            $whatsapp->sendText(
                $this->from,
                'Disculpa, tuve un inconveniente para responderte. El equipo de la doctora te contactará en breve 🙏',
            );
        }
    }

    /**
     * Resuelve la campaña a partir del anuncio Click-to-WhatsApp. Si no existe
     * una campaña con ese ID de anuncio, la crea automáticamente con el título y
     * el texto del anuncio, para que aparezca en el panel de Campañas.
     */
    /**
     * Descarga el archivo que mandó la paciente y lo guarda en el disco público
     * para que la doctora pueda verlo en la bandeja. Devuelve el mismo formato
     * de la columna `media` que ya usa el bot para su material del catálogo.
     *
     * Si la descarga falla, el mensaje se guarda igual (sin adjunto): perder el
     * texto por culpa de una foto sería peor.
     *
     * @return list<array<string,string>>
     */
    private function guardarAdjunto(WhatsAppService $whatsapp, Conversation $conversation): array
    {
        if (! $this->media) {
            return [];
        }

        $archivo = $whatsapp->downloadMedia((string) $this->media['id']);
        if (! $archivo) {
            return [];
        }

        $ruta = sprintf(
            'whatsapp/%d/%s.%s',
            $conversation->id,
            Str::uuid(),
            $this->extensionDe((string) ($this->media['mime'] ?? ''), (string) ($this->media['filename'] ?? '')),
        );

        Storage::disk('public')->put($ruta, $archivo['contents']);

        return [[
            'type' => (string) $this->media['kind'],
            'url' => Storage::disk('public')->url($ruta),
            'caption' => (string) ($this->media['caption'] ?? ''),
            'filename' => (string) ($this->media['filename'] ?? ''),
        ]];
    }

    /**
     * Extensión a partir del mime que reporta WhatsApp, con el nombre original
     * como respaldo. El mime suele traer parámetros ("audio/ogg; codecs=opus"),
     * por eso se corta en el punto y coma.
     */
    private function extensionDe(string $mime, string $filename): string
    {
        $mime = trim(explode(';', $mime)[0]);

        $conocidos = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
            'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
            'audio/amr' => 'amr', 'audio/aac' => 'aac',
            'application/pdf' => 'pdf',
        ];

        if (isset($conocidos[$mime])) {
            return $conocidos[$mime];
        }

        $delNombre = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,5}$/', $delNombre) ? $delNombre : 'bin';
    }

    /**
     * ¿Este número queda fuera del modo prueba? Con la lista blanca vacía nadie
     * queda fuera (comportamiento normal); con números cargados, todo el que no
     * esté en ella se queda sin respuesta automática.
     */
    private function fueraDeLaListaDePrueba(): bool
    {
        $numeros = Settings::whatsappTestNumbers();

        return $numeros !== [] && ! Settings::phoneInList($this->from, $numeros);
    }

    private function resolveCampaign(User $doctor): ?Campaign
    {
        $sourceId = $this->referral['source_id'] ?? null;
        if (blank($sourceId)) {
            return null;
        }

        // Si Meta Ads está conectado, emparejamos con la campaña real importada.
        // El referral trae el ID del ANUNCIO, no de la campaña, así que pedimos
        // a la Marketing API la campaña padre (lo cacheamos un día).
        $ads = MetaAdsService::fromConfig();
        if ($ads->isConfigured()) {
            $campaignId = Cache::remember(
                'meta_ad_campaign_'.$sourceId,
                now()->addDay(),
                fn () => $ads->resolveAdCampaignId((string) $sourceId),
            );

            if (filled($campaignId)) {
                $matched = $doctor->campaigns()->where('meta_campaign_id', $campaignId)->first();
                if ($matched) {
                    return $matched;
                }
            }
        }

        $headline = trim((string) ($this->referral['headline'] ?? ''));
        $body = trim((string) ($this->referral['body'] ?? ''));

        // Sin Meta Ads o sin coincidencia: auto-registro por ID de anuncio.
        return $doctor->campaigns()->firstOrCreate(
            ['meta_campaign_id' => (string) $sourceId],
            [
                'name' => $headline !== '' ? Str::limit($headline, 250, '') : 'Anuncio '.$sourceId,
                'offer' => $body !== '' ? $body : null,
                'platform' => 'meta',
                'is_active' => true,
            ],
        );
    }
}
