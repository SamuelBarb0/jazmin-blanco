<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Message;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lore le contestaba a toda nota de voz «no puedo escucharla, escríbemelo».
 * Ahora la nota se transcribe con Whisper (Groq por defecto) al llegar: Lore
 * lee el texto y la doctora lo ve bajo el audio en la bandeja. Si el proveedor
 * falla, todo sigue como antes.
 */
class NotasDeVozTranscritasTest extends TestCase
{
    use RefreshDatabase;

    private const LINEA = '111111111111111';

    private const NOTA_SIN_OIR = '[La paciente envió una nota de voz. No puedes escucharla: pídele con amabilidad que te lo escriba, y avísale que la doctora también la va a escuchar.]';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', self::LINEA);
        config()->set('services.whatsapp.api_version', 'v21.0');
        config()->set('services.transcripcion.key', 'gsk_prueba');
        config()->set('services.transcripcion.base_url', 'https://api.groq.com/openai/v1');
        config()->set('services.transcripcion.model', 'whisper-large-v3-turbo');

        // Con el bot apagado el job guarda el mensaje y se detiene: así se
        // prueba la transcripción sin llamar a Claude.
        User::factory()->create();
        Settings::put('whatsapp_bot_enabled', '0');
    }

    /** @param  \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface  $groq */
    private function fakeMetaYGroq($groq): void
    {
        Http::fake([
            'graph.facebook.com/v21.0/media-audio-1' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1',
                'mime_type' => 'audio/ogg; codecs=opus',
                'file_size' => 9,
            ]),
            'lookaside.fbsbx.com/*' => Http::response('OggS-fake', 200, ['Content-Type' => 'audio/ogg']),
            'api.groq.com/*' => $groq,
        ]);
    }

    private function llegaNotaDeVoz(): Message
    {
        (new ProcessWhatsAppMessage(
            from: '573001112233',
            text: self::NOTA_SIN_OIR,
            media: ['kind' => 'audio', 'id' => 'media-audio-1', 'mime' => 'audio/ogg; codecs=opus', 'caption' => '', 'filename' => ''],
            phoneNumberId: self::LINEA,
        ))->handle();

        return Message::sole();
    }

    public function test_la_nota_de_voz_llega_a_lore_como_texto_y_queda_bajo_el_audio(): void
    {
        $this->fakeMetaYGroq(Http::response(['text' => ' Hola doctora, quería saber el precio del implante capilar. ']));

        $mensaje = $this->llegaNotaDeVoz();

        $this->assertStringContainsString('transcrita automáticamente', $mensaje->content);
        $this->assertStringEndsWith('Hola doctora, quería saber el precio del implante capilar.', $mensaje->content);
        $this->assertSame('Hola doctora, quería saber el precio del implante capilar.', $mensaje->media[0]['transcript']);
        $this->assertSame('audio', $mensaje->media[0]['type']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.groq.com/openai/v1/audio/transcriptions'
            && $r->hasHeader('Authorization', 'Bearer gsk_prueba')
            && str_contains($r->body(), 'whisper-large-v3-turbo')
            && str_contains($r->body(), 'name="language"')
            && str_contains($r->body(), 'OggS-fake'));
    }

    public function test_si_el_proveedor_falla_lore_sigue_pidiendo_que_lo_escriba(): void
    {
        $this->fakeMetaYGroq(Http::response(['error' => ['message' => 'rate limit']], 429));

        $mensaje = $this->llegaNotaDeVoz();

        $this->assertSame(self::NOTA_SIN_OIR, $mensaje->content);
        $this->assertArrayNotHasKey('transcript', $mensaje->media[0]);
        // El audio se guardó igual: la doctora lo puede escuchar.
        $this->assertNotEmpty($mensaje->media[0]['url']);
    }

    public function test_sin_llave_no_se_llama_a_nadie(): void
    {
        config()->set('services.transcripcion.key', null);
        $this->fakeMetaYGroq(Http::response(['text' => 'no debería']));

        $mensaje = $this->llegaNotaDeVoz();

        $this->assertSame(self::NOTA_SIN_OIR, $mensaje->content);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq'));
    }

    public function test_una_imagen_no_se_manda_a_transcribir(): void
    {
        Http::fake([
            'graph.facebook.com/v21.0/media-foto' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/jpeg']),
            'lookaside.fbsbx.com/*' => Http::response('jpg', 200),
        ]);

        (new ProcessWhatsAppMessage(
            from: '573001112233',
            text: '[El paciente envió una imagen]',
            media: ['kind' => 'image', 'id' => 'media-foto', 'mime' => 'image/jpeg', 'caption' => '', 'filename' => ''],
            phoneNumberId: self::LINEA,
        ))->handle();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq'));
    }
}
