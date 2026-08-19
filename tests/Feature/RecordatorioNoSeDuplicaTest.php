<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A William le llegó el mismo recordatorio dos veces seguidas, el 19 de agosto de
 * 2026 a las 13:00:03. No fue un caso aislado: pasó cinco veces entre el 10 y el
 * 19 de agosto, siempre en el segundo :03 de una hora en punto.
 *
 * La causa era el orden. El comando enviaba y DESPUÉS marcaba la cita, así que
 * dos corridas que coincidieran en el mismo minuto leían las dos la cita como
 * pendiente y las dos escribían a la paciente. `withoutOverlapping` protege al
 * scheduler de sí mismo, pero no de una segunda corrida lanzada por fuera.
 *
 * El arreglo no depende de cuántos procesos haya: la cita se RESERVA con un
 * UPDATE condicional antes de enviar, y solo un proceso puede ganarlo.
 */
class RecordatorioNoSeDuplicaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', '111111111111111');
        config()->set('services.whatsapp.api_version', 'v21.0');

        Settings::put('reminders_enabled', '1');
        Settings::put('whatsapp_bot_enabled', '1');
        Settings::put('whatsapp_test_numbers', '');
    }

    private function citaDeManana(): Appointment
    {
        $user = User::factory()->create();

        return Appointment::create([
            'user_id' => $user->id,
            'patient_name' => 'William Briñez',
            'patient_phone' => '3107730230',
            // Dentro de la ventana del aviso de 24 h. Se deja holgado a
            // propósito: la ventana se calcula en la zona del consultorio y la
            // cita se guarda en la de la app, y entre las dos hay 5 horas.
            'starts_at' => now()->addHours(10),
            'ends_at' => now()->addHours(11),
            'status' => 'scheduled',
        ]);
    }

    public function test_la_cita_se_marca_antes_de_enviar_no_despues(): void
    {
        $cita = $this->citaDeManana();
        $marcadaAlMomentoDeEnviar = null;

        // El fake se ejecuta EN MEDIO del envío: es el único instante en el que
        // se puede comprobar si otra corrida simultánea vería la cita libre.
        Http::fake(function () use ($cita, &$marcadaAlMomentoDeEnviar) {
            $marcadaAlMomentoDeEnviar = Appointment::find($cita->id)->reminder_24h_sent_at !== null;

            return Http::response(['messages' => [['id' => 'wamid.1']]], 200);
        });

        $this->artisan('appointments:send-reminders --force')->assertSuccessful();

        $this->assertTrue(
            $marcadaAlMomentoDeEnviar,
            'La cita debe quedar reservada ANTES de escribirle a la paciente; si no, dos corridas simultáneas envían dos veces.'
        );
    }

    public function test_una_segunda_corrida_no_vuelve_a_escribir(): void
    {
        $this->citaDeManana();

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $this->artisan('appointments:send-reminders --force')->assertSuccessful();
        $primera = count(Http::recorded());

        $this->artisan('appointments:send-reminders --force')->assertSuccessful();

        $this->assertSame($primera, count(Http::recorded()), 'La segunda corrida no debe enviar nada.');
    }

    public function test_si_el_envio_falla_la_reserva_se_suelta_para_reintentar(): void
    {
        $cita = $this->citaDeManana();

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'caida']], 500)]);

        $this->artisan('appointments:send-reminders --force')->assertSuccessful();

        $this->assertNull(
            $cita->fresh()->reminder_24h_sent_at,
            'Un envío fallido no puede dejar la cita marcada: la paciente se quedaría sin recordatorio para siempre.'
        );
    }
}
