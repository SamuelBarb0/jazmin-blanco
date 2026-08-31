<?php

namespace Tests\Feature;

use App\Http\Controllers\Concerns\MediaParaWhatsApp;
use App\Jobs\CompressUploadedVideo;
use App\Models\Service;
use App\Models\ServiceMedia;
use App\Models\User;
use App\Services\VideoTranscoder;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Que la doctora pueda subir el video tal como se lo da el móvil.
 *
 * Rechazar lo que se pasa del tope evita el fallo, pero le pasa el trabajo a
 * ella: un reel de 30 s sale de un iPhone en 35 MB y no va a comprimirlo a mano
 * de forma consistente — así llegamos al fallo del 20-ago-2026. Con ffmpeg en
 * el servidor se acepta y se recomprime en cola.
 *
 * Solo se toca lo que NO cabe: los videos que ya están bien se dejan como
 * están, porque recomprimirlos solo les quitaría calidad.
 */
class VideoGrandeSeOptimizaSoloTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * `VideoTranscoder::binario()` exige un ejecutable de verdad. El binario de
     * PHP sirve y existe en cualquier máquina donde corran estos tests; el job
     * nunca llega a ejecutarlo porque se le inyecta un transcoder de mentira.
     */
    private function conFfmpeg(): void
    {
        config()->set('media.ffmpeg', PHP_BINARY);
    }

    private function sinFfmpeg(): void
    {
        config()->set('media.ffmpeg', null);
    }

    /** @return array{0: User, 1: Service} */
    private function consultorio(): array
    {
        $doctora = User::factory()->create();
        $doctora->forceFill(['cuenta_id' => $doctora->id])->save();

        return [$doctora, Service::create(['user_id' => $doctora->id, 'name' => 'Implante Capilar'])];
    }

    private function medioDe(Service $servicio, int $bytes, string $nombre = 'grande.mp4'): ServiceMedia
    {
        $ruta = "service-media/{$servicio->id}/{$nombre}";
        Storage::disk('public')->put($ruta, str_repeat('x', $bytes));

        return $servicio->media()->create([
            'user_id' => $servicio->user_id,
            'type' => 'video',
            'path' => $ruta,
            'sort_order' => 1,
        ]);
    }

    public function test_con_ffmpeg_el_panel_acepta_el_video_grande_en_vez_de_rechazarlo(): void
    {
        $this->conFfmpeg();
        [$doctora, $servicio] = $this->consultorio();

        $respuesta = $this->actingAs($doctora)->post(route('services.media.store', $servicio), [
            'type' => 'video',
            'file' => UploadedFile::fake()->create('evolucion.mp4', 35 * 1024, 'video/mp4'),
            'caption' => 'EVOLUCION 4 MESES IMPLANTE CAPILAR',
        ]);

        $respuesta->assertSessionHasNoErrors();
        $this->assertSame(1, $servicio->media()->count());
    }

    public function test_lo_que_se_pasa_del_tope_se_manda_a_la_cola(): void
    {
        Queue::fake();
        $this->conFfmpeg();
        [, $servicio] = $this->consultorio();

        $this->decisor()->decidir($this->medioDe($servicio, WhatsAppService::LIMITE_VIDEO + 1));

        Queue::assertPushed(CompressUploadedVideo::class);
    }

    public function test_sin_ffmpeg_se_sigue_rechazando_en_el_momento(): void
    {
        Queue::fake();
        $this->sinFfmpeg();
        [$doctora, $servicio] = $this->consultorio();

        $respuesta = $this->actingAs($doctora)->post(route('services.media.store', $servicio), [
            'type' => 'video',
            'file' => UploadedFile::fake()->create('evolucion.mp4', 35 * 1024, 'video/mp4'),
        ]);

        // Sin forma de arreglarlo, avisar al subir es mejor que guardar algo
        // que después no se puede enviar.
        $respuesta->assertSessionHasErrors('file');
        $this->assertSame(0, ServiceMedia::count());
        Queue::assertNothingPushed();
    }

    public function test_un_video_que_ya_cabe_no_se_manda_a_comprimir(): void
    {
        Queue::fake();
        $this->conFfmpeg();
        [, $servicio] = $this->consultorio();

        // Recomprimir lo que ya está bien solo le quitaría calidad: los tres
        // videos sanos del servicio son H.264 de 6-10 MB y no hay que tocarlos.
        $this->decisor()->decidir($this->medioDe($servicio, 9 * 1024 * 1024, 'cabe.mp4'));

        Queue::assertNothingPushed();
    }

    public function test_sin_ffmpeg_no_se_encola_nada_aunque_se_pase(): void
    {
        Queue::fake();
        $this->sinFfmpeg();
        [, $servicio] = $this->consultorio();

        $this->decisor()->decidir($this->medioDe($servicio, WhatsAppService::LIMITE_VIDEO + 1));

        Queue::assertNothingPushed();
    }

    public function test_ni_siquiera_con_ffmpeg_se_acepta_algo_disparatado(): void
    {
        $this->conFfmpeg();
        [$doctora, $servicio] = $this->consultorio();

        $respuesta = $this->actingAs($doctora)->post(route('services.media.store', $servicio), [
            'type' => 'video',
            'file' => UploadedFile::fake()->create('enorme.mp4', 250 * 1024, 'video/mp4'),
        ]);

        $respuesta->assertSessionHasErrors('file');
    }

    public function test_el_job_escribe_con_nombre_nuevo_y_borra_el_original(): void
    {
        $this->conFfmpeg();
        [, $servicio] = $this->consultorio();
        $medio = $this->medioDe($servicio, WhatsAppService::LIMITE_VIDEO + 1);
        $rutaVieja = $medio->path;

        (new CompressUploadedVideo($medio))->handle($this->transcoderQueFunciona());

        $medio->refresh();

        // Nombre nuevo A PROPÓSITO: delante de storage/ hay una CDN que cachea
        // por URL, y al reemplazar conservando el nombre sus edges siguieron
        // sirviendo la versión vieja durante horas.
        $this->assertNotSame($rutaVieja, $medio->path);
        $this->assertTrue(Storage::disk('public')->exists($medio->path));
        $this->assertFalse(Storage::disk('public')->exists($rutaVieja));
        $this->assertLessThanOrEqual(WhatsAppService::LIMITE_VIDEO, Storage::disk('public')->size($medio->path));
    }

    public function test_el_job_deja_en_paz_lo_que_ya_cabe(): void
    {
        $this->conFfmpeg();
        [, $servicio] = $this->consultorio();
        $medio = $this->medioDe($servicio, 5 * 1024 * 1024, 'cabe.mp4');
        $rutaOriginal = $medio->path;

        $transcoder = $this->transcoderQueFunciona();
        (new CompressUploadedVideo($medio))->handle($transcoder);

        $this->assertFalse($transcoder->llamado, 'no debería recomprimir un video que ya cabe');
        $this->assertSame($rutaOriginal, $medio->refresh()->path);
    }

    public function test_si_la_compresion_falla_no_se_pierde_lo_que_subio_la_doctora(): void
    {
        $this->conFfmpeg();
        [, $servicio] = $this->consultorio();
        $medio = $this->medioDe($servicio, WhatsAppService::LIMITE_VIDEO + 1);
        $rutaOriginal = $medio->path;

        $roto = new class extends VideoTranscoder
        {
            public function comprimir(string $origen, string $destino, int $limite): bool
            {
                return false;
            }
        };

        (new CompressUploadedVideo($medio))->handle($roto);

        // No se puede enviar —de eso ya se encarga la guarda de sendMedia—,
        // pero borrarle el material sería peor que dejarlo ahí.
        $this->assertSame($rutaOriginal, $medio->refresh()->path);
        $this->assertTrue(Storage::disk('public')->exists($rutaOriginal));
    }

    /**
     * Expone la decisión del trait, que es lo que se quiere probar.
     *
     * No se hace por HTTP porque `UploadedFile::fake()->create()` declara un
     * tamaño pero guarda un archivo vacío: el medio quedaría en 0 bytes y la
     * prueba pasaría por la razón equivocada. Aquí los archivos son reales.
     */
    private function decisor(): object
    {
        return new class
        {
            use MediaParaWhatsApp;

            public function decidir(ServiceMedia $medio): void
            {
                $this->optimizarSiHaceFalta($medio);
            }
        };
    }

    private function transcoderQueFunciona(): VideoTranscoder
    {
        return new class extends VideoTranscoder
        {
            public bool $llamado = false;

            public function comprimir(string $origen, string $destino, int $limite): bool
            {
                $this->llamado = true;
                file_put_contents($destino, str_repeat('y', 1024 * 1024));

                return true;
            }
        };
    }
}
