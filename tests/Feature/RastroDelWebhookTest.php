<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\WebhookHit;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * El 21-sep-2026 una paciente escribió cinco veces en tres días y no hubo
 * respuesta. En la base no había ni rastro: ni el texto, ni el chat, ni un
 * error. Desde fuera, «Meta no nos lo entregó» y «lo perdimos nosotros» se ven
 * exactamente igual, y con el número en la nube de Meta no hay un teléfono
 * donde mirar.
 *
 * Estas pruebas fijan el rastro que las separa.
 */
class RastroDelWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const LINEA = '111111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', self::LINEA);
        config()->set('services.whatsapp.api_version', 'v21.0');

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200)]);
    }

    /** @param  array<string,mixed>  $extra */
    private function entrega(string $wamid, string $from = '573001112233', array $extra = []): void
    {
        $this->postJson('/api/webhooks/whatsapp', [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => self::LINEA],
                        'contacts' => [['profile' => ['name' => 'Paciente']]],
                        'messages' => [array_merge([
                            'id' => $wamid,
                            'from' => $from,
                            'type' => 'text',
                            'text' => ['body' => 'Hola, quiero una cita de valoración'],
                        ], $extra)],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    public function test_todo_lo_que_entrega_meta_queda_anotado_antes_de_procesarse(): void
    {
        Queue::fake();

        $this->entrega('wamid.uno');

        $rastro = WebhookHit::sole();

        $this->assertSame('wamid.uno', $rastro->wamid);
        $this->assertSame('573001112233', $rastro->from_phone);
        $this->assertSame(self::LINEA, $rastro->phone_number_id);
        $this->assertSame('text', $rastro->tipo);
        $this->assertSame(WebhookHit::RESULTADO_ENCOLADO, $rastro->resultado);
    }

    public function test_un_reintento_de_meta_se_anota_como_duplicado_y_no_se_procesa_dos_veces(): void
    {
        Queue::fake();

        $this->entrega('wamid.repetido');
        $this->entrega('wamid.repetido');

        Queue::assertPushed(ProcessWhatsAppMessage::class, 1);

        $this->assertSame(
            [WebhookHit::RESULTADO_ENCOLADO, WebhookHit::RESULTADO_DUPLICADO],
            WebhookHit::orderBy('id')->pluck('resultado')->all(),
        );
    }

    public function test_un_mensaje_procesado_nunca_se_queda_en_encolado(): void
    {
        User::factory()->create();
        Settings::put('whatsapp_bot_enabled', '1');

        WebhookHit::create(['wamid' => 'wamid.dos', 'from_phone' => '573001112233']);

        (new ProcessWhatsAppMessage(
            from: '573001112233',
            text: 'hola, quiero información',
            phoneNumberId: self::LINEA,
            wamid: 'wamid.dos',
        ))->handle();

        $rastro = WebhookHit::sole();

        // Lo que se fija: el rastro AVANZA y queda atado a su chat. Quedarse en
        // «encolado» es justo la huella de un mensaje que entró y se perdió, y
        // es lo que el resumen diario denuncia.
        $this->assertNotContains($rastro->resultado, [
            WebhookHit::RESULTADO_RECIBIDO,
            WebhookHit::RESULTADO_ENCOLADO,
        ]);
        $this->assertNotNull($rastro->conversation_id);

        // Y el mensaje de la paciente está guardado, que es lo que de verdad
        // importa: aunque nadie lo conteste, la doctora puede verlo.
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $rastro->conversation_id,
            'role' => 'user',
        ]);
    }

    public function test_un_chat_en_pausa_deja_dicho_que_no_se_respondio_por_la_pausa(): void
    {
        $doctora = User::factory()->create();
        Settings::put('whatsapp_bot_enabled', '1');
        $lead = Lead::create(['user_id' => $doctora->id, 'name' => 'Paciente', 'phone' => '573001112233']);

        Conversation::create([
            'user_id' => $doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => 'WhatsApp · Paciente',
            'bot_enabled' => false,
            'bot_paused_manually' => true,
        ]);

        WebhookHit::create(['wamid' => 'wamid.tres', 'from_phone' => '573001112233']);

        (new ProcessWhatsAppMessage(
            from: '573001112233',
            text: '¿Sigue en pie mi cita?',
            phoneNumberId: self::LINEA,
            wamid: 'wamid.tres',
        ))->handle();

        $rastro = WebhookHit::sole();

        $this->assertSame(WebhookHit::RESULTADO_IGNORADO, $rastro->resultado);
        $this->assertStringContainsString('pausa', (string) $rastro->detalle);
    }

    public function test_con_el_bot_apagado_el_rastro_dice_que_fue_el_interruptor(): void
    {
        User::factory()->create();
        Settings::put('whatsapp_bot_enabled', '0');

        WebhookHit::create(['wamid' => 'wamid.cuatro', 'from_phone' => '573001112233']);

        (new ProcessWhatsAppMessage(
            from: '573001112233',
            text: 'hola',
            phoneNumberId: self::LINEA,
            wamid: 'wamid.cuatro',
        ))->handle();

        $this->assertSame('bot apagado', WebhookHit::sole()->detalle);
    }

    public function test_el_comando_distingue_lo_que_nunca_llego_de_lo_que_se_ignoro(): void
    {
        WebhookHit::create([
            'wamid' => 'wamid.cinco',
            'from_phone' => '573009998877',
            'resultado' => WebhookHit::RESULTADO_IGNORADO,
            'detalle' => 'Lore en pausa en este chat',
        ]);

        // El número que sí escribió: sale con su motivo.
        $this->artisan('whatsapp:entradas', ['--telefono' => '3009998877'])
            ->expectsOutputToContain('Lore en pausa')
            ->assertSuccessful();

        // El número del que no hay nada: se dice con todas las letras que sus
        // mensajes no llegaron, que es la diferencia que costó una tarde.
        $this->artisan('whatsapp:entradas', ['--telefono' => '3001234567'])
            ->expectsOutputToContain('NO llegaron a la plataforma')
            ->assertSuccessful();
    }

    public function test_el_resumen_diario_avisa_de_lo_que_entro_y_no_llego_a_ningun_chat(): void
    {
        User::factory()->create();

        $perdido = WebhookHit::create([
            'wamid' => 'wamid.seis',
            'from_phone' => '573001112233',
            'resultado' => WebhookHit::RESULTADO_ENCOLADO,
        ]);

        // Con el margen de 15 minutos por delante: lo recién encolado no cuenta.
        $this->artisan('resumen:diario', ['--no-enviar' => true])
            ->doesntExpectOutputToContain('NO llegaron a ningún chat');

        WebhookHit::whereKey($perdido->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('resumen:diario', ['--no-enviar' => true])
            ->expectsOutputToContain('NO llegaron a ningún chat')
            ->assertSuccessful();
    }
}
