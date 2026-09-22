<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Services\BotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lore no puede confirmar una cita que no tocó.
 *
 * Caso de Andrea Catalina (22/09/2026): su cita ya no existía —la habían
 * borrado del panel—, `reagendar_cita` respondió «no encuentro ninguna cita...
 * NO le digas que se la moviste», y treinta segundos después Lore escribió
 * «¡Listo! Encontré tu cita y la reprogramé para hoy a las 4:00 p. m.». La
 * paciente se quedó esperando una cita que la agenda nunca tuvo, y la doctora
 * la descubrió al mirar la agenda.
 *
 * La instrucción ya estaba en el resultado de la herramienta y no bastó, así
 * que la guarda es de código: si el texto afirma un cambio de agenda y ninguna
 * herramienta lo hizo en ese turno, el mensaje no sale y el chat se escala.
 */
class CitaInventadaTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    private Conversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.anthropic.key', 'sk-de-prueba');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        $this->doctora = User::factory()->create();

        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Andrea Catalina Sanchez Q',
            'phone' => '573123667068',
        ]);

        $this->conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'bot_enabled' => true,
        ]);

        $this->conversacion->messages()->create([
            'role' => 'user',
            'content' => 'Será posible hoy 4pm?',
        ]);
    }

    /** Lo que el modelo contesta, sin pedir ninguna herramienta. */
    private function loreDice(string $texto): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $texto]],
                'stop_reason' => 'end_turn',
            ], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200),
        ]);
    }

    private function respuesta(): string
    {
        return BotService::fromUser($this->doctora)->reply($this->conversacion->fresh())['text'];
    }

    public function test_no_manda_el_mensaje_que_confirma_una_cita_que_no_existe(): void
    {
        $this->loreDice('¡Listo! 😊 Encontré tu cita y la reprogramé para hoy martes 22 de septiembre a las 4:00 p. m.');

        $texto = $this->respuesta();

        $this->assertStringNotContainsString('reprogramé', $texto);
        $this->assertStringContainsString('equipo del consultorio', $texto);
        $this->assertSame(0, Appointment::count(), 'no debería haber inventado ninguna cita');
    }

    public function test_deja_el_chat_escalado_para_que_lo_atienda_una_persona(): void
    {
        $this->loreDice('¡Listo! Tu cita quedó agendada para hoy a las 4:00 p. m.');

        $this->respuesta();

        $this->conversacion->refresh();

        $this->assertFalse((bool) $this->conversacion->bot_enabled);
        $this->assertNotNull($this->conversacion->escalated_at);
        $this->assertStringContainsString('NO quedó registrada', (string) $this->conversacion->escalation_reason);
    }

    /** Hablar de una cita sin afirmar que se acaba de mover no es una promesa. */
    public function test_sigue_pudiendo_hablar_de_la_cita_sin_tocarla(): void
    {
        $texto = 'Tu cita es el jueves 24 de septiembre a las 8:00 a. m. en Cra 16 A #82-46 😊';
        $this->loreDice($texto);

        $this->assertSame($texto, $this->respuesta());
        $this->assertTrue((bool) $this->conversacion->fresh()->bot_enabled);
    }

    /** Ni ofrecer horarios, que es lo que debe hacer cuando no encuentra la cita. */
    public function test_puede_ofrecer_horarios_sin_que_la_guarda_se_meta(): void
    {
        $texto = 'Estos son los espacios libres de hoy: 4:00 p. m. y 5:00 p. m. ¿Cuál te sirve? 😊';
        $this->loreDice($texto);

        $this->assertSame($texto, $this->respuesta());
    }
}
