<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Convierte en texto las notas de voz que mandan las pacientes, para que Lore
 * pueda responderlas. Claude no procesa audio, así que lo hace un tercero con
 * Whisper.
 *
 * Habla el formato de OpenAI (`POST /audio/transcriptions`), que Groq copia tal
 * cual: cambiar de proveedor es cambiar `TRANSCRIPTION_BASE_URL`,
 * `TRANSCRIPTION_MODEL` y la llave en el `.env`, sin tocar código. Por defecto
 * Groq, que tiene plan gratuito; la doctora no quería otra suscripción.
 *
 * Nunca lanza: si la transcripción falla, la nota de voz sigue guardada y Lore
 * vuelve a lo de antes (pedirle a la paciente que lo escriba).
 */
class TranscripcionService
{
    public function __construct(
        private readonly ?string $key,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout = 30,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            key: config('services.transcripcion.key'),
            baseUrl: rtrim((string) config('services.transcripcion.base_url'), '/'),
            model: (string) config('services.transcripcion.model'),
            timeout: (int) config('services.transcripcion.timeout', 30),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->key);
    }

    /**
     * Texto de la nota de voz, o null si no se pudo (sin llave, error del
     * proveedor, audio vacío).
     *
     * Sin `prompt` a propósito: probado con notas reales, darle a Whisper el
     * vocabulario del consultorio no corrigió los nombres (Endolift salió
     * «Endolib») y le hizo inventar una frase que la paciente no dijo. Los
     * nombres mal escritos los cubre Lore confirmando con la paciente.
     */
    public function transcribir(string $audio, string $filename): ?string
    {
        if (! $this->isConfigured() || $audio === '') {
            return null;
        }

        try {
            $respuesta = Http::withToken($this->key)
                ->acceptJson()
                ->timeout($this->timeout)
                ->attach('file', $audio, $filename)
                ->post("{$this->baseUrl}/audio/transcriptions", [
                    'model' => $this->model,
                    // Sin idioma, Whisper a veces «traduce» audios cortos o con
                    // ruido a inglés. Las pacientes escriben en español.
                    'language' => 'es',
                    'response_format' => 'json',
                    'temperature' => '0',
                ]);
        } catch (Throwable $e) {
            Log::error('No se pudo transcribir una nota de voz.', ['error' => $e->getMessage()]);

            return null;
        }

        if ($respuesta->failed()) {
            Log::error('El proveedor de transcripción rechazó una nota de voz.', [
                'status' => $respuesta->status(),
                'error' => $respuesta->json('error') ?? mb_substr($respuesta->body(), 0, 500),
            ]);

            return null;
        }

        $texto = trim((string) $respuesta->json('text'));

        return $texto !== '' ? $texto : null;
    }
}
