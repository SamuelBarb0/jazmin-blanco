<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\BotService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Sin comprobante no hay cita.
 *
 * Antes bastaba con que la paciente dijera «pago por Nequi» para que Lore le
 * agendara en el acto: la cita nacía con una marca roja de «transferencia sin
 * verificar» y la apuesta era que esa marca bastara para atajarlo. No bastó.
 * La doctora se encontraba citas sin pagar, las borraba a mano y el espacio se
 * perdía igual, porque quien no pagó tampoco aparece.
 *
 * Ahora la cita solo nace cuando llega el comprobante, y el horario NO se
 * aparta mientras tanto: le sigue saliendo libre a las demás pacientes. Es una
 * decisión explícita de la doctora, no un descuido — perder el cupo mientras
 * alguien paga es preferible a bloquearlo por alguien que quizá no pague.
 */
class TransferenciaSinComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private const DATOS_PAGO = 'BANCOLOMBIA - Ahorros: 320000500 / NEQUI: 3165394709';

    private const NOTA_COMPROBANTE = '[El paciente envió una imagen por WhatsApp (posible comprobante de pago de la valoración).]';

    private User $user;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        $this->user = User::factory()->create();

        $lead = Lead::create([
            'user_id' => $this->user->id,
            'name' => 'Sory Cruz',
            'phone' => '573182499643',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'lead_id' => $lead->id,
            'bot_enabled' => true,
        ]);

        Settings::setBotConfig(['clinic_payment' => self::DATOS_PAGO]);

        // El seguimiento respeta el interruptor general: con el bot apagado
        // no sale ni un mensaje automatico, y eso es lo correcto.
        Settings::setWhatsappBotEnabled(true);
    }

    public function test_sin_comprobante_no_se_agenda_ni_se_aparta_el_horario(): void
    {
        $respuesta = $this->agendarPorTransferencia();

        $this->assertSame(0, Appointment::count(), 'Se creó una cita sin comprobante.');
        $this->assertStringContainsString('ERROR', $respuesta);
        $this->assertStringContainsString('comprobante', $respuesta);
    }

    public function test_queda_constancia_de_lo_acordado_para_poder_insistirle(): void
    {
        $this->agendarPorTransferencia();

        $solicitud = TransferRequest::pendientes()->first();

        $this->assertNotNull($solicitud, 'No se registró la solicitud de transferencia.');
        $this->assertSame($this->conversation->id, $solicitud->conversation_id);
        $this->assertStringStartsWith('2026-09-04 16:00', (string) $solicitud->cuando());
        $this->assertNotNull($solicitud->expires_at);
    }

    public function test_le_llegan_los_datos_de_pago_aunque_no_se_agende(): void
    {
        $respuesta = $this->agendarPorTransferencia();

        $this->assertStringContainsString('320000500', $respuesta);
        $this->assertStringContainsString('3165394709', $respuesta);
    }

    public function test_no_se_le_puede_decir_que_quedo_apartada(): void
    {
        $respuesta = $this->agendarPorTransferencia();

        // El guion que recibe el modelo tiene que prohibirlo explícitamente:
        // decirle «quedó apartada» es justo lo que hacía antes.
        $this->assertStringContainsString('NO le digas que su cita quedó apartada', $respuesta);
    }

    public function test_insistir_no_duplica_la_solicitud_ni_aplaza_el_plazo(): void
    {
        $this->agendarPorTransferencia();
        $vence = TransferRequest::pendientes()->first()->expires_at;

        $this->travel(2)->hours();
        $this->agendarPorTransferencia();

        $this->assertSame(1, TransferRequest::count(), 'Se duplicó la solicitud al insistir.');
        $this->assertSame(
            $vence->toDateTimeString(),
            TransferRequest::pendientes()->first()->expires_at->toDateTimeString(),
            'Volver a preguntar aplazó el vencimiento: así no caducaría nunca.',
        );
    }

    /**
     * La contraparte imprescindible: si esta se rompe, ninguna transferencia
     * llegaria nunca a ser cita y el bloqueo pasaria inadvertido, porque
     * «no se agendo» es justo lo que las demas pruebas esperan.
     *
     * No se comprueba la cita creada sino que la GUARDA deja pasar: crearla de
     * verdad exige Google Calendar, que aqui no esta configurado y que ya
     * cubren las pruebas del agendamiento. Lo nuevo es la decision, no el alta.
     */
    public function test_con_comprobante_la_guarda_deja_pasar(): void
    {
        $this->conversation->messages()->create([
            'role' => 'user',
            'content' => self::NOTA_COMPROBANTE,
        ]);

        try {
            $respuesta = $this->agendarPorTransferencia();
        } catch (RuntimeException $e) {
            // Llego hasta Google: eso ya significa que paso la guarda.
            $this->assertStringContainsString('Google Calendar', $e->getMessage());
            $this->assertSame(0, TransferRequest::pendientes()->count(), 'La solicitud siguio pendiente pese al comprobante.');

            return;
        }

        $this->assertStringNotContainsString('NO agendes todavía', $respuesta);
    }

    /** El detector, aislado: es la pieza de la que cuelga todo lo anterior. */
    public function test_el_detector_solo_cuenta_imagenes_recientes_de_la_paciente(): void
    {
        $detector = new ReflectionMethod(BotService::class, 'comprobanteReciente');
        $detector->setAccessible(true);
        $bot = BotService::fromUser($this->user)->forConversation($this->conversation);

        $this->assertFalse($detector->invoke($bot), 'Sin nada enviado ya daba por bueno el comprobante.');

        // Lo que manda la clinica no cuenta, aunque hable de comprobantes.
        $this->conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Quedamos atentos al posible comprobante de pago.',
        ]);
        $this->assertFalse($detector->invoke($bot), 'Un mensaje de Lore se conto como comprobante de la paciente.');

        $this->conversation->messages()->create([
            'role' => 'user',
            'content' => self::NOTA_COMPROBANTE,
        ]);
        $this->assertTrue($detector->invoke($bot), 'No reconocio la imagen que envio la paciente.');
    }

    public function test_una_foto_vieja_no_sirve_de_comprobante(): void
    {
        $mensaje = $this->conversation->messages()->create([
            'role' => 'user',
            'content' => self::NOTA_COMPROBANTE,
        ]);
        $mensaje->forceFill(['created_at' => now()->subHours(9)])->save();

        $this->agendarPorTransferencia();

        $this->assertSame(0, Appointment::count(), 'Una imagen de hace 9 horas se coló como comprobante.');
    }

    public function test_lo_que_ella_escribe_no_cuenta_como_comprobante(): void
    {
        $this->conversation->messages()->create([
            'role' => 'user',
            'content' => 'ya te mando el comprobante en un rato',
        ]);

        $this->agendarPorTransferencia();

        $this->assertSame(0, Appointment::count(), 'Prometer el comprobante bastó para agendar.');
    }

    public function test_el_seguimiento_le_recuerda_una_sola_vez(): void
    {
        $this->agendarPorTransferencia();
        $this->conversation->messages()->create(['role' => 'user', 'content' => 'listo']);

        $this->travel(4)->hours();
        $this->artisan('transfers:follow-up')->assertSuccessful();

        $solicitud = TransferRequest::first();
        $this->assertNotNull($solicitud->reminded_at, 'No se le recordó el comprobante.');
        $this->assertSame(TransferRequest::PENDIENTE, $solicitud->status);

        $antes = $this->conversation->messages()->count();
        $this->artisan('transfers:follow-up')->assertSuccessful();

        $this->assertSame($antes, $this->conversation->messages()->count(), 'Le insistió dos veces.');
    }

    public function test_al_vencer_se_le_avisa_que_el_horario_quedo_libre(): void
    {
        $this->agendarPorTransferencia();
        $this->conversation->messages()->create(['role' => 'user', 'content' => 'listo']);

        // Dentro de la ventana de 24 h, que es donde el aviso puede salir.
        $this->travel(20)->hours();
        $this->artisan('transfers:follow-up')->assertSuccessful();

        $this->assertSame(TransferRequest::VENCIDA, TransferRequest::first()->status);
        $this->assertStringContainsString(
            'quedó disponible para otras pacientes',
            (string) $this->conversation->messages()->latest('id')->first()?->content,
        );
    }

    public function test_fuera_de_la_ventana_de_24h_no_se_le_escribe_pero_se_cierra(): void
    {
        $this->agendarPorTransferencia();

        // Sin ningún mensaje entrante reciente: WhatsApp no deja texto libre.
        $this->travel(30)->hours();
        $this->artisan('transfers:follow-up')->assertSuccessful();

        $this->assertSame(TransferRequest::VENCIDA, TransferRequest::first()->status);
        Http::assertNothingSent();
    }

    /** Llama a la herramienta tal como lo haría el modelo al elegir Nequi. */
    private function agendarPorTransferencia(): string
    {
        $bot = BotService::fromUser($this->user)->forConversation($this->conversation);

        $metodo = new ReflectionMethod(BotService::class, 'toolBook');
        $metodo->setAccessible(true);

        return $metodo->invoke($bot, [
            'fecha_hora' => '2026-09-04 16:00',
            'nombre_paciente' => 'Sory Cruz',
            'telefono' => '573182499643',
            'servicio' => 'Valoración',
            'pago_por_transferencia' => true,
        ]);
    }
}
