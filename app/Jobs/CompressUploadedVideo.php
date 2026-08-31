<?php

namespace App\Jobs;

use App\Models\CampaignMedia;
use App\Models\ServiceMedia;
use App\Services\VideoTranscoder;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deja un video subido por debajo del tope de WhatsApp.
 *
 * Va en cola y no en la petición web porque comprimir el reel de 31 s tarda
 * ~58 s en el Hostinger, muy por encima de lo que aguanta un formulario.
 *
 * VENTANA CONOCIDA: entre que se sube el archivo y termina este job, el medio
 * ya está en la base y el bot podría intentar mandarlo. No pasa nada grave —
 * `WhatsAppService::sendMedia()` lo mide antes de llamar a Meta y se lo salta,
 * dejando la fila en `delivery_failures`—, y en cuanto el job acaba se arregla
 * solo. Es un minuto y solo si alguien pregunta justo por ese servicio.
 */
class CompressUploadedVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Por debajo del `retry_after` de la cola (300 s), o se daría por abandonado mientras corre. */
    public int $timeout = 280;

    public int $tries = 2;

    public function __construct(
        public readonly ServiceMedia|CampaignMedia $medium,
    ) {}

    public function handle(VideoTranscoder $transcoder): void
    {
        $medio = $this->medium->fresh();

        if (! $medio || $medio->type !== 'video' || blank($medio->path)) {
            return;
        }

        $disco = Storage::disk('public');
        $limite = WhatsAppService::limiteBytes('video');

        if (! $disco->exists($medio->path) || $disco->size($medio->path) <= $limite) {
            return; // ya cabe: no se toca, recomprimir solo le quitaría calidad
        }

        $origen = $disco->path($medio->path);

        // NOMBRE NUEVO A PROPÓSITO, no se sobrescribe el original. Delante de
        // `storage/` hay una CDN (hcdn) que guarda el objeto por URL: al
        // reemplazar un archivo conservando el nombre, sus edges siguieron
        // sirviendo la versión vieja durante horas, y con peticiones por rango
        // llegaron a mezclar las dos y devolver un archivo corrupto. Con un
        // nombre nuevo la URL nace limpia y no depende de que nadie purgue.
        $rutaNueva = dirname($medio->path).'/'.Str::random(40).'.mp4';
        $destino = $disco->path($rutaNueva);

        @mkdir(dirname($destino), 0755, true);

        $antes = $disco->size($medio->path);

        if (! $transcoder->comprimir($origen, $destino, $limite)) {
            // Se deja el original como está: no se puede enviar, pero el fallo
            // ya está en el log y la guarda de envío evita que se intente a
            // ciegas. Perder lo que subió la doctora sería peor.
            Log::error('No se pudo optimizar un video subido; queda el original.', [
                'medio' => $medio::class.'#'.$medio->id,
                'path' => $medio->path,
                'bytes' => $antes,
            ]);

            return;
        }

        @chmod($destino, 0644);

        $rutaVieja = $medio->path;
        $medio->update(['path' => $rutaNueva]);

        // El original solo se borra DESPUÉS de que la fila apunte al nuevo, y
        // solo porque este archivo se acaba de subir: nadie lo ha enviado
        // todavía, así que ninguna URL guardada en `messages.media` lo
        // referencia. Con material antiguo NO se puede hacer esto.
        $disco->delete($rutaVieja);

        Log::info('Video optimizado para WhatsApp.', [
            'medio' => $medio::class.'#'.$medio->id,
            'antes' => $antes,
            'despues' => $disco->size($rutaNueva),
        ]);
    }
}
