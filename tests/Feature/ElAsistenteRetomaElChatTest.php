<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La pausa que no caducaba (reporte de la doctora, 26-ago-2026: «paciente pide
 * reprogramar y no da opciones, y me toca entrar a mí en el desgaste de hacerlo
 * manual»).
 *
 * Lo que pasó de verdad, según los datos de producción: la paciente escribió
 * «Necesito reprogramarla» a las 6:38 de la mañana de su cita y NADIE le
 * contestó — ni el asistente, que llevaba días en pausa en ese chat porque
 * alguien le había escrito a mano una vez, ni una persona, hasta las 8:24, ya
 * pasada la hora de la cita. La misma mañana el asistente respondió con
 * normalidad a las otras doce pacientes que escribieron.
 *
 * Y no se veía: la guarda de `ProcessWhatsAppMessage` es un `return` mudo, en
 * producción `LOG_LEVEL=error`, y no hubo ningún error. Cinco chats más estaban
 * igual el día del reporte.
 */
class ElAsistenteRetomaElChatTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();
    }

    private function chatPausado(string $cuando, bool $porElBoton = false): Conversation
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
            'bot_paused_at' => $cuando,
            'bot_paused_manually' => $porElBoton,
        ]);

        // El último mensaje del hilo es el que la doctora escribió al pausarlo.
        // La fecha se fuerza por la base: `created_at` no es fillable y
        // Eloquent lo pisaría con la de ahora, que es justo lo contrario de lo
        // que esta prueba necesita.
        $mensaje = Message::create([
            'conversation_id' => $conversacion->id,
            'role' => 'assistant',
            'sent_by' => 'human',
            'content' => 'Claro, yo te confirmo.',
        ]);
        Message::whereKey($mensaje->id)->update(['created_at' => $cuando, 'updated_at' => $cuando]);

        return $conversacion;
    }

    public function test_tras_horas_de_silencio_el_asistente_retoma_el_chat(): void
    {
        $conversacion = $this->chatPausado(now()->subDay()->toDateTimeString());

        $this->assertTrue($conversacion->debeReanudarAlAsistente());
    }

    /** Mientras la conversación está viva, el chat sigue siendo de la doctora. */
    public function test_no_lo_retoma_si_la_doctora_acaba_de_escribir(): void
    {
        $conversacion = $this->chatPausado(now()->subMinutes(30)->toDateTimeString());

        $this->assertFalse($conversacion->debeReanudarAlAsistente());
    }

    /** El botón «Pausar a Lore» es una decisión, y las decisiones no caducan solas. */
    public function test_la_pausa_del_boton_no_caduca(): void
    {
        $conversacion = $this->chatPausado(now()->subDays(5)->toDateTimeString(), porElBoton: true);

        $this->assertFalse($conversacion->debeReanudarAlAsistente());
    }

    /** Un chat escalado espera a una persona: volver a hablar sería desdecirse. */
    public function test_un_chat_escalado_no_se_retoma_solo(): void
    {
        $conversacion = $this->chatPausado(now()->subDays(5)->toDateTimeString());
        $conversacion->forceFill(['escalated_at' => now()->subDays(5)])->save();

        $this->assertFalse($conversacion->debeReanudarAlAsistente());
    }

    /** Puesto a 0 vuelve el comportamiento anterior, por si hay que dar marcha atrás. */
    public function test_se_puede_apagar_la_reanudacion(): void
    {
        Settings::put('bot_resume_hours', '0');

        $conversacion = $this->chatPausado(now()->subDays(5)->toDateTimeString());

        $this->assertFalse($conversacion->debeReanudarAlAsistente());
    }

    /**
     * La prueba que importa: el mensaje entrante se guarda ANTES de decidir, así
     * que si la decisión se tomara después del guardado el chat siempre
     * parecería recién hablado y no se reanudaría nunca.
     */
    public function test_el_mensaje_recien_llegado_no_cuenta_como_actividad(): void
    {
        $conversacion = $this->chatPausado(now()->subDays(3)->toDateTimeString());

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', '111111111111111');
        Settings::put('whatsapp_bot_enabled', '1');

        // El job muere después en `isReady()` (no hay clave de IA en las
        // pruebas), pero para entonces la reanudación ya está decidida y
        // guardada, que es justo lo que se comprueba.
        (new ProcessWhatsAppMessage('573134951777', 'Necesito reprogramarla', 'Marcela'))->handle();

        $conversacion->refresh();

        $this->assertTrue($conversacion->bot_enabled);
        $this->assertNull($conversacion->bot_paused_at);
    }

    /** Escribir a mano pausa, pero deja la pausa marcada como automática. */
    public function test_escribir_a_mano_deja_una_pausa_que_si_caduca(): void
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
            'bot_enabled' => true,
        ]);

        // Ventana abierta: la paciente escribió hace un momento.
        Message::create([
            'conversation_id' => $conversacion->id,
            'role' => 'user',
            'content' => 'Hola',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200),
        ]);
        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', '111111111111111');

        $this->actingAs($this->doctora)
            ->post(route('inbox.send', $conversacion), ['content' => 'Yo te confirmo.']);

        $conversacion->refresh();

        $this->assertFalse($conversacion->bot_enabled);
        $this->assertFalse($conversacion->bot_paused_manually);
    }

    /** El botón deja la pausa marcada como decisión. */
    public function test_el_boton_deja_una_pausa_que_no_caduca(): void
    {
        $conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'channel' => 'whatsapp',
            'title' => 'Marcela',
            'bot_enabled' => true,
        ]);

        $this->actingAs($this->doctora)->patch(route('inbox.toggle', $conversacion));

        $conversacion->refresh();

        $this->assertFalse($conversacion->bot_enabled);
        $this->assertTrue($conversacion->bot_paused_manually);

        // Y al reactivarla, la marca se limpia.
        $this->actingAs($this->doctora)->patch(route('inbox.toggle', $conversacion));

        $this->assertFalse($conversacion->refresh()->bot_paused_manually);
    }

    /** Lo que hacía falta para verlo: la bandeja señala a quien nadie respondió. */
    public function test_la_bandeja_marca_los_chats_sin_responder(): void
    {
        $conversacion = $this->chatPausado(now()->subDays(3)->toDateTimeString(), porElBoton: true);

        Message::create([
            'conversation_id' => $conversacion->id,
            'role' => 'user',
            'content' => 'Necesito reprogramarla',
        ]);

        $this->actingAs($this->doctora)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('conversations.0.sin_responder', true));
    }
}
