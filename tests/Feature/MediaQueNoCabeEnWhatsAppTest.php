<?php

namespace Tests\Feature;

use App\Models\DeliveryFailure;
use App\Models\Service;
use App\Models\ServiceMedia;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El fallo que se comió once días de la campaña que mejor rendía.
 *
 * El 20-ago-2026 se subió al servicio «Implante Capilar» un video de 35 MB
 * («EVOLUCION 4 MESES»). El panel lo aceptó porque validaba `max:51200` —50 MB
 * para todo— pero WhatsApp corta en 16 MB. Desde esa misma tarde y hasta el
 * 31-ago, cada vez que el bot se lo mandaba a una paciente, Meta lo rechazaba
 * con el acuse `131053`.
 *
 * Lo que lo volvió invisible es que el rechazo es ASÍNCRONO: Meta responde 200,
 * `sendMedia()` devuelve `true` y el video figura como enviado en la bandeja; el
 * `failed` llega después por el webhook. La doctora lo daba por entregado y la
 * paciente recibía la secuencia con un hueco justo en el video de la evolución.
 *
 * Dos cierres, uno por cada punto de entrada:
 * 1. el panel ya no acepta lo que WhatsApp va a rechazar;
 * 2. y si un archivo grande ya está guardado, no se envía a ciegas: se mide
 *    antes y queda constancia donde se ve.
 */
class MediaQueNoCabeEnWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFONO = '573001112233';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', '111111111111111');
        config()->set('services.whatsapp.api_version', 'v21.0');

        Storage::fake('public');
    }

    /** URL pública de un archivo del disco `public` con el peso que se le pida. */
    private function archivoDe(int $bytes, string $nombre): string
    {
        Storage::disk('public')->put($nombre, str_repeat('x', $bytes));

        return Storage::disk('public')->url($nombre);
    }

    public function test_un_video_mas_grande_que_el_tope_no_se_manda_a_meta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $url = $this->archivoDe(WhatsAppService::LIMITE_VIDEO + 1, 'service-media/30/evolucion.mp4');

        $enviado = WhatsAppService::fromConfig()->sendMedia(self::TELEFONO, 'video', $url, 'EVOLUCION 4 MESES');

        $this->assertFalse($enviado);
        Http::assertNothingSent();
    }

    public function test_el_rechazo_queda_anotado_donde_se_ve(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $url = $this->archivoDe(WhatsAppService::LIMITE_VIDEO + 1, 'service-media/30/evolucion.mp4');

        WhatsAppService::fromConfig()->sendMedia(self::TELEFONO, 'video', $url, '');

        // Mismo sitio y mismo código que usaría el acuse de Meta, para que caiga
        // en el resumen diario junto a los demás fallos de entrega.
        $fallo = DeliveryFailure::sole();
        $this->assertSame(self::TELEFONO, $fallo->phone);
        $this->assertSame(131053, (int) $fallo->code);
        $this->assertStringContainsString('evolucion.mp4', (string) $fallo->details);
    }

    public function test_un_video_que_cabe_se_envia_normal(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.2']]], 200)]);

        $url = $this->archivoDe(WhatsAppService::LIMITE_VIDEO, 'service-media/30/cabe.mp4');

        $this->assertTrue(WhatsAppService::fromConfig()->sendMedia(self::TELEFONO, 'video', $url));

        Http::assertSent(fn ($req) => $req['type'] === 'video' && $req['video']['link'] === $url);
        $this->assertSame(0, DeliveryFailure::count());
    }

    public function test_la_imagen_se_mide_con_su_propio_tope_que_es_menor(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.3']]], 200)]);

        // 6 MB: cabría de sobra como video, pero como imagen no.
        $url = $this->archivoDe(WhatsAppService::LIMITE_IMAGEN + 1, 'service-media/50/antes.png');

        $this->assertFalse(WhatsAppService::fromConfig()->sendMedia(self::TELEFONO, 'image', $url));
        Http::assertNothingSent();
    }

    public function test_una_url_externa_se_envia_sin_medir(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.4']]], 200)]);

        // No es nuestra, no se puede pesar sin una petición de red; y esa
        // petición iría dentro del job que le responde a la paciente.
        $externa = 'https://cdn.ejemplo.com/video.mp4';

        $this->assertTrue(WhatsAppService::fromConfig()->sendMedia(self::TELEFONO, 'video', $externa));
        Http::assertSent(fn ($req) => $req['video']['link'] === $externa);
    }

    public function test_el_panel_ya_no_acepta_un_video_que_whatsapp_rechazaria(): void
    {
        [$doctora, $servicio] = $this->consultorio();

        $respuesta = $this->actingAs($doctora)->post(route('services.media.store', $servicio), [
            'type' => 'video',
            'file' => UploadedFile::fake()->create('evolucion.mp4', 35 * 1024, 'video/mp4'),
        ]);

        $respuesta->assertSessionHasErrors('file');
        $this->assertSame(0, ServiceMedia::count());
    }

    public function test_el_panel_explica_por_que_lo_rechaza(): void
    {
        [$doctora, $servicio] = $this->consultorio();

        $respuesta = $this->actingAs($doctora)->post(route('services.media.store', $servicio), [
            'type' => 'image',
            'file' => UploadedFile::fake()->create('antes.png', 8 * 1024, 'image/png'),
        ]);

        // El mensaje tiene que nombrar el tope real del tipo que se subió, que
        // es el dato con el que la doctora puede arreglar el archivo.
        $respuesta->assertSessionHasErrors(['file' => 'WhatsApp no acepta imágenes de más de 5 MB, así que este archivo nunca le llegaría a la paciente. Súbelo comprimido.']);
    }

    public function test_el_panel_sigue_aceptando_lo_que_si_cabe(): void
    {
        [$doctora, $servicio] = $this->consultorio();

        $respuesta = $this->actingAs($doctora)->post(route('services.media.store', $servicio), [
            'type' => 'video',
            'file' => UploadedFile::fake()->create('cabe.mp4', 9 * 1024, 'video/mp4'),
            'caption' => 'EVOLUCION 4 MESES IMPLANTE CAPILAR',
        ]);

        $respuesta->assertSessionHasNoErrors();
        $this->assertSame(1, $servicio->media()->count());
    }

    /** @return array{0: User, 1: Service} */
    private function consultorio(): array
    {
        $doctora = User::factory()->create();
        $doctora->forceFill(['cuenta_id' => $doctora->id])->save();

        $servicio = Service::create(['user_id' => $doctora->id, 'name' => 'Implante Capilar']);

        return [$doctora, $servicio];
    }
}
