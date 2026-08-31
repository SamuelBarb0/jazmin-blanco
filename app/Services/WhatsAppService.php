<?php

namespace App\Services;

use App\Models\DeliveryFailure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
    /**
     * Topes de peso que acepta la Cloud API, por tipo de archivo.
     *
     * Importan más de lo que parece porque el rechazo es ASÍNCRONO: Meta
     * descarga el archivo desde la URL que le pasamos y, si no le sirve, ya nos
     * respondió 200. El fallo llega después como acuse `131053` y mientras
     * tanto el mensaje figura como enviado en la bandeja. Por eso se mide
     * antes de llamar a la API en vez de esperar el acuse.
     */
    public const LIMITE_IMAGEN = 5 * 1024 * 1024;

    public const LIMITE_VIDEO = 16 * 1024 * 1024;

    public function __construct(
        private readonly ?string $token,
        private readonly ?string $phoneId,
        private readonly string $apiVersion = 'v21.0',
    ) {}

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
     * La misma configuración pero enviando por OTRA línea.
     *
     * Se responde por el número que RECIBIÓ el mensaje, no por el del `.env`:
     * si hay más de una línea suscrita al webhook, contestar siempre por la de
     * configuración le escribe a la paciente desde un número que ella nunca
     * escribió — y Meta lo rechaza con `131047`, porque en esa línea no hay
     * ventana de 24 h abierta.
     *
     * Sin id, o si es el mismo, se devuelve la instancia tal cual.
     */
    public function forPhone(?string $phoneId): self
    {
        if (blank($phoneId) || $phoneId === $this->phoneId) {
            return $this;
        }

        return new self($this->token, $phoneId, $this->apiVersion);
    }

    /** Línea por la que envía esta instancia. */
    public function phoneId(): ?string
    {
        return $this->phoneId;
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

        if (! $this->cabeEnWhatsApp($to, $type, $url)) {
            return false;
        }

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

    /** Bytes que admite WhatsApp para un 'image' o un 'video'. */
    public static function limiteBytes(string $type): int
    {
        return $type === 'video' ? self::LIMITE_VIDEO : self::LIMITE_IMAGEN;
    }

    /** El mismo tope en MB, para los mensajes que lee la doctora. */
    public static function limiteMb(string $type): int
    {
        return intdiv(self::limiteBytes($type), 1024 * 1024);
    }

    /**
     * ¿El archivo cabe en el tope de WhatsApp?
     *
     * Solo se puede medir lo que vive en nuestro disco público, que es de donde
     * salen las fotos y videos de servicios y campañas. Una URL externa se deja
     * pasar: medirla costaría una petición de red dentro del job que le está
     * respondiendo a la paciente, y ese job ya se quedó sin respuesta una vez
     * por esperar a Meta.
     *
     * Si no cabe, se anota en `delivery_failures` —el mismo sitio donde caería
     * el acuse de Meta— y NO se llama a la API. Así el fallo sale en el resumen
     * diario en vez de aparecer como enviado en la bandeja.
     */
    private function cabeEnWhatsApp(string $to, string $type, string $url): bool
    {
        $bytes = $this->pesoEnDisco($url);
        $limite = self::limiteBytes($type);

        if ($bytes === null || $bytes <= $limite) {
            return true;
        }

        Log::warning('Archivo demasiado grande para WhatsApp; no se envía.', [
            'para' => $to,
            'tipo' => $type,
            'url' => $url,
            'bytes' => $bytes,
            'limite' => $limite,
        ]);

        DeliveryFailure::create([
            'phone' => $to,
            'code' => 131053,
            'title' => 'Media upload error',
            'details' => sprintf(
                'Comprobado antes de enviar: el %s pesa %d bytes y el tope de WhatsApp es %d. Archivo: %s',
                $type === 'video' ? 'video' : 'la imagen',
                $bytes,
                $limite,
                $url,
            ),
            'created_at' => now(),
        ]);

        return false;
    }

    /**
     * Peso en disco del archivo al que apunta la URL, si es una de las nuestras
     * (`APP_URL/storage/…`). Para cualquier otra cosa, null.
     */
    private function pesoEnDisco(string $url): ?int
    {
        $disco = Storage::disk('public');
        $base = rtrim($disco->url('/'), '/');

        if ($base === '' || ! Str::startsWith($url, $base.'/')) {
            return null;
        }

        $relativa = ltrim(rawurldecode(Str::after($url, $base)), '/');

        return $disco->exists($relativa) ? $disco->size($relativa) : null;
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
