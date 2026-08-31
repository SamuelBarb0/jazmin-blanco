<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Recomprime video para que quepa en lo que acepta WhatsApp.
 *
 * Existe porque el material lo sube la doctora desde el móvil y un reel de 30
 * segundos sale de un iPhone en 35 MB y en HEVC: más del doble del tope de la
 * Cloud API, y en un códec que no está entre los soportados. Pedirle que
 * comprima a mano es pedirle algo que no va a pasar de forma consistente — de
 * hecho así llegamos al fallo del 20-ago-2026.
 */
class VideoTranscoder
{
    /**
     * Hilos del ENCODER.
     *
     * En el Hostinger `nproc` dice 64, así que ffmpeg en automático abre ~64
     * hilos de x264 —cada uno con sus búferes de fotograma— y revienta el
     * límite de memoria de la jaula. El error no menciona la memoria y despista
     * del todo: «Error while opening encoder - maybe incorrect parameters such
     * as bit_rate, rate, width or height». Medido a 1080x1920: con 1, 2, 4 y 8
     * funciona; solo el automático falla. Pasar de 2 a 4 no compensa (58 s
     * contra 56 s), porque la CPU la limita la jaula igual.
     *
     * OJO: `-threads` tiene que ir DESPUÉS del `-i`. Antes se lo come el
     * decodificador y no arregla nada.
     */
    private const HILOS = 2;

    /** Calidad objetivo. 23 da ~11,5 MB para un reel de 31 s a 1080x1920. */
    private const CRF = 23;

    /**
     * Cuánto del tope se intenta ocupar como máximo.
     *
     * No se apunta al 100%: el contenedor añade lo suyo y el control de tasa no
     * es exacto. Al 80% queda margen para no volver a quedarnos justos.
     */
    private const MARGEN = 0.80;

    /** Techo de duración del proceso. El de 31 s tarda ~58 s. */
    private const TIMEOUT = 240;

    /** Ruta al binario, si está configurado y se puede ejecutar. */
    public static function binario(): ?string
    {
        $ruta = config('media.ffmpeg');

        return filled($ruta) && is_file($ruta) && is_executable($ruta) ? $ruta : null;
    }

    public static function disponible(): bool
    {
        return self::binario() !== null;
    }

    /**
     * Comprime `$origen` en `$destino` hasta caber en `$limite` bytes.
     *
     * Devuelve false y no deja `$destino` si algo sale mal, para que quien
     * llame pueda quedarse con el original en vez de con un archivo a medias.
     */
    public function comprimir(string $origen, string $destino, int $limite): bool
    {
        $ffmpeg = self::binario();

        if ($ffmpeg === null) {
            Log::warning('Se pidió comprimir video pero no hay ffmpeg configurado.', ['origen' => $origen]);

            return false;
        }

        $argumentos = [
            $ffmpeg, '-y', '-v', 'error',
            '-i', $origen,
            '-c:v', 'libx264',
            '-threads', (string) self::HILOS,
            '-preset', 'slow',
            '-crf', (string) self::CRF,
            '-profile:v', 'high',
            '-pix_fmt', 'yuv420p',
        ];

        // Con la duración se puede poner un techo de tasa que garantice el
        // tamaño; sin ella se va solo con CRF, que para un reel corto basta
        // pero no asegura nada en un video largo.
        if ($techo = $this->techoDeTasa($ffmpeg, $origen, $limite)) {
            $argumentos = [...$argumentos, '-maxrate', $techo.'k', '-bufsize', (2 * $techo).'k'];
        }

        $argumentos = [...$argumentos,
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            $destino,
        ];

        $resultado = Process::timeout(self::TIMEOUT)->run($argumentos);

        if (! $resultado->successful()) {
            Log::error('ffmpeg falló al comprimir un video.', [
                'origen' => $origen,
                'salida' => mb_substr($resultado->errorOutput() ?: $resultado->output(), 0, 500),
            ]);
            @unlink($destino);

            return false;
        }

        if (! is_file($destino) || filesize($destino) < 1024) {
            Log::error('ffmpeg terminó bien pero no dejó un archivo usable.', ['destino' => $destino]);
            @unlink($destino);

            return false;
        }

        if (filesize($destino) > $limite) {
            Log::error('El video comprimido sigue por encima del tope.', [
                'destino' => $destino,
                'bytes' => filesize($destino),
                'limite' => $limite,
            ]);
            @unlink($destino);

            return false;
        }

        return true;
    }

    /**
     * Tasa de vídeo en kbit/s con la que el archivo debería caber, o null si no
     * se pudo averiguar la duración.
     *
     * La duración se saca del propio ffmpeg (`Duration: 00:00:31.23`) porque en
     * el servidor solo está el binario de ffmpeg, no el de ffprobe.
     */
    private function techoDeTasa(string $ffmpeg, string $origen, int $limite): ?int
    {
        $salida = Process::timeout(30)->run([$ffmpeg, '-hide_banner', '-i', $origen]);
        $texto = $salida->errorOutput().$salida->output();

        if (! preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $texto, $m)) {
            return null;
        }

        $segundos = ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (float) $m[3];

        if ($segundos < 1) {
            return null;
        }

        // (bytes disponibles * 8 bits) / segundos / 1000 = kbit/s, menos lo que
        // se lleva el audio.
        $kbits = (int) floor((($limite * self::MARGEN * 8) / $segundos / 1000) - 128);

        return $kbits > 200 ? $kbits : 200;
    }
}
