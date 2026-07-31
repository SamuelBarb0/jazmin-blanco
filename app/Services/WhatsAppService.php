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
     * Envía una plantilla aprobada por Meta.
     *
     * Es la ÚNICA forma de escribirle al paciente fuera de la ventana de 24h
     * desde su último mensaje — justo el caso de los recordatorios de cita, que
     * salen dos días antes. La plantilla debe existir y estar aprobada en el
     * WhatsApp Manager con este mismo nombre e idioma.
     *
     * @param  list<string>  $params  valores para los {{1}}, {{2}}… del cuerpo
     */
    public function sendTemplate(string $to, string $template, string $language = 'es', array $params = []): bool
    {
        $components = [];
        if ($params !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    // WhatsApp rechaza saltos de línea y tabulaciones en los parámetros.
                    fn ($p) => ['type' => 'text', 'text' => Str::limit(preg_replace('/\s+/', ' ', (string) $p), 1000, '…')],
                    array_values($params),
                ),
            ];
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
                ...($components ? ['components' => $components] : []),
            ],
        ]);
    }

    /**
     * Descarga un archivo que envió la paciente (foto, nota de voz, documento).
     *
     * Son dos pasos: el webhook solo trae un `media_id`, hay que pedirle a Meta
     * la URL temporal y después bajar el binario. Ojo: esa URL **no es pública**,
     * exige el mismo token en la descarga, así que no sirve guardársela ni
     * pasársela al navegador — hay que traer el archivo y almacenarlo nosotros.
     *
     * @return array{contents:string,mime:string,size:int}|null
     */
    public function downloadMedia(string $mediaId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $meta = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(30)
            ->get("https://graph.facebook.com/{$this->apiVersion}/{$mediaId}");

        if ($meta->failed() || blank($meta->json('url'))) {
            Log::error('No se pudo resolver un archivo de WhatsApp.', [
                'media_id' => $mediaId,
                'status' => $meta->status(),
                'error' => $meta->json('error') ?? $meta->body(),
            ]);

            return null;
        }

        $archivo = Http::withToken($this->token)->timeout(60)->get($meta->json('url'));

        if ($archivo->failed()) {
            Log::error('No se pudo descargar un archivo de WhatsApp.', [
                'media_id' => $mediaId,
                'status' => $archivo->status(),
            ]);

            return null;
        }

        return [
            'contents' => $archivo->body(),
            'mime' => (string) ($meta->json('mime_type') ?: $archivo->header('Content-Type')),
            'size' => (int) $meta->json('file_size'),
        ];
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
