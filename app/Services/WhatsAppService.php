<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Envío de mensajes a través de la WhatsApp Cloud API de Meta.
 *
 * El número y el token salen de config/services.php (variables WHATSAPP_*).
 * Mientras se responda dentro de la ventana de 24h desde que el paciente
 * escribió, el texto libre se permite sin plantilla aprobada.
 */
class WhatsAppService
{
    public function __construct(
        private readonly ?string $token,
        private readonly ?string $phoneId,
        private readonly string $apiVersion = 'v21.0',
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            token: config('services.whatsapp.token'),
            phoneId: config('services.whatsapp.phone_id'),
            apiVersion: config('services.whatsapp.api_version', 'v21.0'),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->token) && filled($this->phoneId);
    }

    /**
     * Envía un mensaje de texto al paciente.
     */
    public function sendText(string $to, string $body): bool
    {
        $body = trim($body);
        if ($body === '') {
            return false;
        }

        // WhatsApp limita el cuerpo de texto a 4096 caracteres.
        $body = Str::limit($body, 4090, '…');

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => true,
                'body' => $body,
            ],
        ]);
    }

    /**
     * Envía una foto o un video por URL pública.
     *
     * @param  string  $type  'image' o 'video'
     */
    public function sendMedia(string $to, string $type, string $url, string $caption = ''): bool
    {
        $type = $type === 'video' ? 'video' : 'image';

        $media = ['link' => $url];
        if (trim($caption) !== '') {
            $media['caption'] = Str::limit(trim($caption), 1020, '…');
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $type,
            $type => $media,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function post(array $payload): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('WhatsApp no está configurado (faltan WHATSAPP_ACCESS_TOKEN o WHATSAPP_PHONE_ID).');

            return false;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneId}/messages";

        $response = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(30)
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('Error al enviar mensaje por WhatsApp', [
                'status' => $response->status(),
                'error' => $response->json('error') ?? $response->body(),
            ]);

            return false;
        }

        return true;
    }
}
