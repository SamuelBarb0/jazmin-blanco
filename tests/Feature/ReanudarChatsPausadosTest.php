<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El agujero que quedó del arreglo del 26-ago (visto en producción el 28-ago).
 *
 * La pausa automática ya caducaba, pero la caducidad SOLO se miraba cuando la
 * paciente volvía a escribir. Quien escribe una vez poco después de que le
 * contestaran a mano, y no insiste, no vuelve a disparar nada: la pausa está
 * vencida y no hay quien la levante. Ese día había siete chats así — uno con un
 * «Ya estoy en sala de espera afuera del consultorio» del 24-ago sin responder.
 *
 * `conversations:resume-paused` lo mira cada hora, se escriba o no.
 */
class ReanudarChatsPausadosTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();

        // Una hora decente y fija: el comando no escribe de madrugada, y sin
        // esto la prueba pasaría o no según la hora a la que corra la suite.
        // La franja la mide el comando en la zona del CONSULTORIO, no en la de
        // la aplicación (que en pruebas es UTC): 10:00 en Bogotá son las 15:00
        // en UTC, y fijar la hora en la zona equivocada dejaba la prueba fuera
        // de franja sin que se notara.
        $this->travelTo(Carbon::now(Settings::googleTimezone())->setTime(10, 0));

        Settings::put('whatsapp_bot_enabled', '1');
        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', '111111111111111');
    }

    /**
     * Chat en pausa automática cuyo último mensaje es de la doctora: no hay
     * nadie esperando respuesta.
     */
    private function chatEnPausa(int $horasDeSilencio): Conversation
    {
        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Marcela',
            'phone' => '573134951777',
        ]);

        $conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => 'Marcela',
            'bot_enabled' => false,
            'bot_paused_at' => now()->subHours($horasDeSilencio),
            'bot_paused_manually' => false,
        ]);

        $this->mensaje($conversacion, 'assistant', 'Claro, yo te confirmo.', $horasDeSilencio);

        return $conversacion;
    }

    /** Lo mismo, pero con la paciente hablando de última: alguien espera. */
    private function chatConPacienteEsperando(int $horasDeSilencio): Conversation
    {
        $conversacion = $this->chatEnPausa($horasDeSilencio + 1);

        $this->mensaje($conversacion, 'user', 'Ya estoy afuera del consultorio', $horasDeSilencio);

        return $conversacion;
    }

    private function mensaje(Conversation $c, string $role, string $texto, int $horasAtras): Message
    {
        $cuando = now()->subHours($horasAtras);

        $mensaje = Message::create([
            'conversation_id' => $c->id,
            'role' => $role,
            'sent_by' => $role === 'user' ? null : 'human',
            'content' => $texto,
        ]);

        // `created_at` no es fillable y Eloquent lo pisaría con la de ahora, que
        // es justo lo contrario de lo que estas pruebas necesitan.
        Message::whereKey($mensaje->id)->update(['created_at' => $cuando, 'updated_at' => $cuando]);

        return $mensaje;
    }

    /** Lore contesta «Claro que sí» y WhatsApp acepta todo. */
    private function laIaResponde(string $texto = 'Claro que sí, te esperamos 😊'): void
    {
        config()->set('services.anthropic.key', 'sk-de-prueba');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $texto]],
                'stop_reason' => 'end_turn',
            ], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200),
        ]);
    }

    public function test_reanuda_un_chat_que_lleva_horas_en_pausa_sin_nadie_esperando(): void
    {
        $conversacion = $this->chatEnPausa(13);

        $this->artisan('conversations:resume-paused')->assertSuccessful();

        $conversacion->refresh();

        $this->assertTrue($conversacion->bot_enabled);
        $this->assertNull($conversacion->bot_paused_at);
    }

    /**
     * La prueba que da sentido al comando: la paciente escribió, nadie le
     * contestó, y sin esto se quedaba así para siempre porque no iba a volver a
     * escribir.
     */
    public function test_le_responde_a_la_paciente_que_se_quedo_esperando(): void
    {
        $this->laIaResponde('Claro que sí, ya te atendemos 😊');

        $conversacion = $this->chatConPacienteEsperando(13);

        $this->artisan('conversations:resume-paused')->assertSuccessful();

        $conversacion->refresh();

        $this->assertTrue($conversacion->bot_enabled);
        $this->assertSame('Claro que sí, ya te atendemos 😊', $conversacion->messages()->latest('id')->first()->content);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && ($request['text']['body'] ?? null) === 'Claro que sí, ya te atendemos 😊');
    }

    /**
     * Fuera de la ventana de 24 h no hay forma de contestarle texto libre. Si se
     * reanudara igual, la bandeja apagaría el aviso «Escribió y nadie respondió»
     * y la paciente quedaría igual de muda pero ya sin marca: el peor resultado.
     */
    public function test_no_reanuda_a_quien_espera_si_ya_no_se_le_puede_escribir(): void
    {
        $this->laIaResponde();

        $conversacion = $this->chatConPacienteEsperando(30);

        $this->artisan('conversations:resume-paused')->assertSuccessful();

        $this->assertFalse($conversacion->refresh()->bot_enabled);
        Http::assertNothingSent();
    }

    /** El botón «Pausar a Lore» es una decisión, y las decisiones no caducan. */
    public function test_no_toca_la_pausa_del_boton(): void
    {
        $conversacion = $this->chatEnPausa(72);
        $conversacion->forceFill(['bot_paused_manually' => true])->save();

        $this->artisan('conversations:resume-paused')->assertSuccessful();

        $this->assertFalse($conversacion->refresh()->bot_enabled);
    }

    /** Un chat escalado espera a una persona: volver a hablar sería desdecirse. */
    public function test_no_toca_un_chat_escalado(): void
    {
        $conversacion = $this->chatConPacienteEsperando(13);
        $conversacion->forceFill(['escalated_at' => now()->subHours(14)])->save();

        $this->artisan('conversations:resume-paused')->assertSuccessful();

        $this->assertFalse($conversacion->refresh()->bot_enabled);
    }

    /** De madrugada el mensaje no es más útil, solo despierta a alguien. */
    public function test_fuera_de_la_franja_horaria_no_le_escribe_a_nadie(): void
    {
        $this->laIaResponde();
        $this->travelTo(Carbon::now(Settings::googleTimezone())->setTime(3, 0));

        $conversacion = $this->chatConPacienteEsperando(13);

        $this->artisan('conversations:resume-paused')->assertSuccessful();

        // Se queda en pausa para que la corrida de la mañana lo recoja.
        $this->assertFalse($conversacion->refresh()->bot_enabled);
        Http::assertNothingSent();
    }

    /** El interruptor general es el botón de pánico: apaga también esto. */
    public function test_con_el_bot_apagado_no_hace_nada(): void
    {
        Settings::put('whatsapp_bot_enabled', '0');

        $conversacion = $this->chatEnPausa(13);

        $this->artisan('conversations:resume-paused')->assertSuccessful();

        $this->assertFalse($conversacion->refresh()->bot_enabled);
    }

    /** La simulación no debe tocar nada ni escribirle a nadie. */
    public function test_la_simulacion_no_cambia_nada(): void
    {
        $this->laIaResponde();

        $conversacion = $this->chatConPacienteEsperando(13);

        $this->artisan('conversations:resume-paused', ['--dry-run' => true])->assertSuccessful();

        $this->assertFalse($conversacion->refresh()->bot_enabled);
        Http::assertNothingSent();
    }
}
