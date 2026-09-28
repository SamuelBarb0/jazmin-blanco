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
    /**
     * Pista para Whisper: una frase corta y natural, como las de las pacientes,
     * con los términos que confundía. Corta a propósito: ver transcribir().
     */
    public const PISTA = 'Hola doc, queria preguntar por el Endolift, el dermapen y la toxina.';

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
     * Modelo y pista salen de comparar 4 variantes sobre las mismas 10 notas
     * reales (28-sep-2026): `whisper-large-v3` con la PISTA corta de abajo fue
     * la única que acertó Endolift, «mi doc», «me aclararon» e «implante de
     * cejas» sin inventar nada, y la más rápida (518 ms). Una pista LARGA (la
     * lista de servicios del panel) se probó antes y le hizo inventar frases;
     * el turbo con esta misma pista escribió «mi Dodo» y agregó «Fui de mes.».
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
                    'prompt' => self::PISTA,
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
