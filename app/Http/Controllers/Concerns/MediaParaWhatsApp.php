<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\CompressUploadedVideo;
use App\Models\CampaignMedia;
use App\Models\ServiceMedia;
use App\Services\VideoTranscoder;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Storage;

/**
 * Reglas comunes al material de servicios y de campañas.
 *
 * Las dos pantallas suben lo mismo y con las mismas restricciones, y hasta el
 * 31-ago-2026 las dos tenían copiado el `max:51200` (50 MB) que dejó entrar un
 * video que WhatsApp nunca iba a aceptar. Con un solo sitio, arreglarlo una vez
 * lo arregla en ambas.
 */
trait MediaParaWhatsApp
{
    /**
     * Reglas de validación del archivo subido.
     *
     * El tope depende de si podemos recomprimir: con ffmpeg disponible se
     * acepta un video muy por encima del límite de WhatsApp porque el job lo va
     * a dejar a medida; sin ffmpeg se rechaza de entrada, que es mejor que
     * guardar algo que después no se puede enviar.
     *
     * @return list<string>
     */
    protected function reglasDelArchivo(string $tipo): array
    {
        return [
            'nullable', 'required_without:url', 'file',
            'max:'.intdiv($this->topeDeSubida($tipo), 1024),
            $tipo === 'video' ? 'mimes:mp4,webm,mov,ogg' : 'mimes:jpg,jpeg,png,webp,gif',
        ];
    }

    protected function topeDeSubida(string $tipo): int
    {
        return $this->sePuedeOptimizar($tipo)
            ? (int) config('media.max_upload_video')
            : WhatsAppService::limiteBytes($tipo);
    }

    protected function sePuedeOptimizar(string $tipo): bool
    {
        return $tipo === 'video' && VideoTranscoder::disponible();
    }

    /**
     * El mensaje tiene que nombrar el tope real y qué hacer, que es el dato con
     * el que la doctora puede resolverlo sola.
     */
    protected function mensajeDeTamano(string $tipo): string
    {
        if ($this->sePuedeOptimizar($tipo)) {
            return sprintf(
                'El video pesa más de %d MB. Recórtalo o expórtalo más liviano antes de subirlo.',
                intdiv((int) config('media.max_upload_video'), 1024 * 1024),
            );
        }

        return sprintf(
            'WhatsApp no acepta %s de más de %d MB, así que este archivo nunca le llegaría a la paciente. Súbelo comprimido.',
            $tipo === 'video' ? 'videos' : 'imágenes',
            WhatsAppService::limiteMb($tipo),
        );
    }

    /**
     * Manda a comprimir si el archivo se pasa del tope de WhatsApp.
     *
     * Se comprueba el tamaño real en disco en vez de fiarse del tipo: un video
     * que ya cabe no se toca, porque recomprimirlo solo le quitaría calidad.
     */
    protected function optimizarSiHaceFalta(ServiceMedia|CampaignMedia $medio): void
    {
        if ($medio->type !== 'video' || blank($medio->path) || ! VideoTranscoder::disponible()) {
            return;
        }

        $disco = Storage::disk('public');

        if ($disco->exists($medio->path) && $disco->size($medio->path) > WhatsAppService::limiteBytes('video')) {
            CompressUploadedVideo::dispatch($medio);
        }
    }
}
