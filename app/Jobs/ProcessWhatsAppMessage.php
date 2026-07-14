<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\User;
use App\Services\BotService;
use App\Services\MetaAdsService;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
     * @param  array<string,mixed>|null  $referral  Datos del anuncio Click-to-WhatsApp.
     */
    public function __construct(
        public readonly string $from,
        public readonly string $text,
        public readonly ?string $profileName = null,
        public readonly ?array $referral = null,
    ) {
    }

    public function handle(WhatsAppService $whatsapp): void
    {
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

            // Por ahora solo entendemos texto. Otros formatos reciben un aviso amable.
            if (trim($this->text) === '') {
                $whatsapp->sendText(
                    $this->from,
                    'Por ahora solo puedo leer mensajes de texto 😊 Cuéntame en qué te puedo ayudar.',
                );

                return;
            }

            // Campaña de origen (si vino de un anuncio Click-to-WhatsApp).
            $campaign = $this->resolveCampaign($doctor);

            // Lead por teléfono (lo crea si es la primera vez que escribe).
            $lead = $doctor->leads()->firstOrCreate(
                ['phone' => $this->from],
                [
                    'name' => $this->profileName ?: $this->from,
                    'channel' => 'whatsapp',
                    'source' => $this->referral['headline'] ?? 'whatsapp',
                    'last_contact_at' => now(),
                ],
            );

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

            $conversation->messages()->create([
                'role' => 'user',
                'content' => $this->text,
            ]);

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
