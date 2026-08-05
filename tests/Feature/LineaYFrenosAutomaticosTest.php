<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dos deudas que se cobraron el mismo día.
 *
 * 1. El webhook tiraba `metadata.phone_number_id`, así que se respondía SIEMPRE
 *    por la línea del `.env`. Con dos cuentas de WhatsApp suscritas al mismo
 *    webhook, a quien escribía a la otra se le contestaba desde un número que
 *    nunca escribió — y Meta lo rechazaba con `131047`.
 * 2. Los envíos programados no consultaban el interruptor general ni la lista
 *    de pruebas, así que apagar el bot no los detenía.
 */
class LineaYFrenosAutomaticosTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIGURADA = '111111111111111';

    private const OTRA_LINEA = '999999999999999';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', self::CONFIGURADA);
        config()->set('services.whatsapp.api_version', 'v21.0');
    }

    public function test_se_responde_por_la_linea_que_recibio_el_mensaje(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        WhatsAppService::fromConfig()->forPhone(self::OTRA_LINEA)->sendText('573001112233', 'hola');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/'.self::OTRA_LINEA.'/messages'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/'.self::CONFIGURADA.'/messages'));
    }

    public function test_sin_linea_conocida_se_usa_la_configurada(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.2']]], 200)]);

        WhatsAppService::fromConfig()->forPhone(null)->sendText('573001112233', 'hola');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/'.self::CONFIGURADA.'/messages'));
    }

    public function test_la_conversacion_guarda_por_que_linea_entro(): void
    {
        User::factory()->create();
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);

        (new ProcessWhatsAppMessage(
            from: '573001112233',
            text: 'hola, quiero información',
            phoneNumberId: self::OTRA_LINEA,
        ))->handle();

        $conversacion = Conversation::where('channel', 'whatsapp')->firstOrFail();

        $this->assertSame(self::OTRA_LINEA, $conversacion->phone_number_id);
    }

    public function test_el_interruptor_general_frena_lo_automatico(): void
    {
        Settings::put('whatsapp_bot_enabled', '0');

        $this->assertFalse(Settings::autoMessagingAllows('573001112233'));
    }

    public function test_la_lista_vacia_no_restringe_a_nadie(): void
    {
        Settings::put('whatsapp_bot_enabled', '1');
        Settings::put('whatsapp_test_numbers', '');

        $this->assertTrue(Settings::autoMessagingAllows('573001112233'));
    }

    public function test_con_lista_cargada_solo_pasan_esos_numeros(): void
    {
        Settings::put('whatsapp_bot_enabled', '1');
        Settings::put('whatsapp_test_numbers', '573001112233');

        $this->assertTrue(Settings::autoMessagingAllows('573001112233'));
        $this->assertFalse(Settings::autoMessagingAllows('573009998877'));
    }

    public function test_los_recordatorios_no_salen_con_el_bot_apagado(): void
    {
        Settings::put('reminders_enabled', '1');
        Settings::put('whatsapp_bot_enabled', '0');

        $this->artisan('appointments:send-reminders')
            ->expectsOutputToContain('apagado')
            ->assertSuccessful();
    }
}
