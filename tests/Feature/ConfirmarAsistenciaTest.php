<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Support\ConfirmacionDeAsistencia;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reporte de la doctora del 22/09/2026: «siempre sí o sí debe enviar el
 * recordatorio de CONFIRMAR; como no dice confirmar sino reprogramar, los
 * pacientes no están confirmando y yo no sé si asisten».
 *
 * Esa semana las 32 citas próximas seguían sin confirmar aunque varias
 * pacientes habían escrito «Confirmo» o «Si asistiré gracias»: nada lo
 * registraba. Se confirma ESCRIBIENDO (sin botones), por decisión suya.
 */
class ConfirmarAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    private const LINEA = '111111111111111';

    private const TELEFONO = '573107730230';

    private User $doctora;

    private Lead $lead;

    private Conversation $chat;

    private Appointment $cita;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', self::LINEA);
        config()->set('services.whatsapp.api_version', 'v21.0');
        config()->set('services.anthropic.key', 'sk-de-prueba');

        Settings::put('reminders_enabled', '1');
        Settings::put('whatsapp_bot_enabled', '1');
        Settings::put('whatsapp_test_numbers', '');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Con gusto te ayudo 😊']],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $this->doctora = User::factory()->create();
        $this->lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Martha Lucía Pérez', 'phone' => self::TELEFONO]);
        $this->chat = Conversation::create([
            'user_id' => $this->doctora->id, 'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'bot_enabled' => true,
        ]);
        $this->cita = Appointment::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $this->lead->id,
            'patient_name' => 'Martha Lucía Pérez',
            'patient_phone' => '3107730230',
            'starts_at' => now()->addHours(10),
            'ends_at' => now()->addHours(11),
            'status' => 'scheduled',
        ]);
    }

    private function escribe(string $texto): void
    {
        $this->postJson('/api/webhooks/whatsapp', [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => self::LINEA],
                        'contacts' => [['profile' => ['name' => 'Martha']]],
                        'messages' => [[
                            'id' => 'wamid.'.uniqid(),
                            'from' => self::TELEFONO,
                            'type' => 'text',
                            'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    private function yaSeLeRecordo(): void
    {
        $this->cita->forceFill(['reminder_24h_sent_at' => now()])->save();
        $this->chat->messages()->create([
            'role' => 'assistant',
            'content' => 'Hola Martha 👋 Te recordamos tu cita mañana (miércoles 23 de septiembre a las 10:00 am) en el consultorio. Por favor respóndenos CONFIRMO para confirmar tu asistencia. Si no puedes asistir, cuéntanos y te ayudamos a reprogramarla.',
        ]);
    }

    private function seLlamoALore(): bool
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com'))->isNotEmpty();
    }

    private function loQueSeLeContesto(): ?string
    {
        return $this->chat->messages()->where('role', 'assistant')->latest('id')->value('content');
    }

    public function test_un_confirmo_tras_el_recordatorio_confirma_y_contesta_sin_lore(): void
    {
        $this->yaSeLeRecordo();

        $this->escribe('Hola buenas tardes Confirmo Gracias');

        $this->assertNotNull($this->cita->fresh()->asistencia_confirmada_at);
        $this->assertStringContainsString('¡Gracias, Martha! ✅ Tu asistencia quedó confirmada', $this->loQueSeLeContesto());
        $this->assertFalse($this->seLlamoALore());
    }

    /**
     * Caso de Flor Elena (visto en producción el 22/09/2026): contestó
     * «Confirmo la cita para mañana 8:00 am» cuando esa cita ya había pasado,
     * y su siguiente cita era el 29/09, de la que nadie le había preguntado.
     */
    public function test_una_respuesta_tardia_no_confirma_otra_cita_que_no_se_recordo(): void
    {
        $this->cita->forceFill([
            'starts_at' => now()->subHours(5), 'ends_at' => now()->subHours(4), 'reminder_24h_sent_at' => now()->subDay(),
        ])->save();
        $siguiente = Appointment::create([
            'user_id' => $this->doctora->id, 'lead_id' => $this->lead->id, 'patient_name' => 'Martha Lucía Pérez',
            'starts_at' => now()->addDays(7), 'ends_at' => now()->addDays(7)->addHour(), 'status' => 'scheduled',
        ]);
        $this->chat->messages()->create([
            'role' => 'assistant',
            'content' => 'Hola Martha 👋 Te recordamos tu cita mañana (…) en el consultorio. Si necesitas reprogramarla, respóndenos por este chat.',
        ]);

        $this->escribe('Confirmo la cita para mañana 8:00 am');

        $this->assertNull($siguiente->fresh()->asistencia_confirmada_at);
    }

    public function test_tambien_vale_tras_el_recordatorio_viejo(): void
    {
        // El que sigue saliendo hasta que Meta apruebe el nuevo.
        $this->cita->forceFill(['reminder_24h_sent_at' => now()])->save();
        $this->chat->messages()->create([
            'role' => 'assistant',
            'content' => 'Hola Martha 👋 Te recordamos tu cita mañana (miércoles 23 de septiembre a las 10:00 am) en el consultorio. Si necesitas reprogramarla, respóndenos por este chat.',
        ]);

        $this->escribe('Si asistiré gracias');

        $this->assertNotNull($this->cita->fresh()->asistencia_confirmada_at);
    }

    public function test_un_confirmo_sin_recordatorio_previo_no_confirma(): void
    {
        $this->escribe('Confirmo');

        $this->assertNull($this->cita->fresh()->asistencia_confirmada_at);
    }

    public function test_pedir_cambio_tras_el_recordatorio_no_confirma(): void
    {
        $this->yaSeLeRecordo();

        $this->escribe('Buenos días, podríamos correrla para las 3 pm?');

        $this->assertNull($this->cita->fresh()->asistencia_confirmada_at);
        $this->assertTrue($this->seLlamoALore());
    }

    public function test_confirmar_con_una_pregunta_confirma_y_deja_que_lore_conteste(): void
    {
        $this->yaSeLeRecordo();

        $this->escribe('Confirmo, ¿dónde puedo parquear?');

        $this->assertNotNull($this->cita->fresh()->asistencia_confirmada_at);
        $this->assertTrue($this->seLlamoALore());
    }

    public function test_con_lore_en_pausa_se_confirma_igual_pero_no_se_contesta(): void
    {
        $this->yaSeLeRecordo();
        $this->chat->forceFill(['bot_enabled' => false, 'bot_paused_manually' => true])->save();

        $this->escribe('Confirmo');

        $this->assertNotNull($this->cita->fresh()->asistencia_confirmada_at);
        $this->assertStringContainsString('Te recordamos tu cita', $this->loQueSeLeContesto(), 'no debió contestar nada');
    }

    public function test_si_se_retracta_enseguida_la_confirmacion_se_quita_y_lore_sigue(): void
    {
        $this->yaSeLeRecordo();
        $this->escribe('Confirmo');
        $this->assertNotNull($this->cita->fresh()->asistencia_confirmada_at);

        $this->escribe('Uy perdón, me equivoqué, esa hora no me queda');

        $this->assertNull($this->cita->fresh()->asistencia_confirmada_at);
        $this->assertTrue($this->seLlamoALore());
    }

    public function test_un_gracias_despues_de_confirmar_no_quita_nada(): void
    {
        $this->yaSeLeRecordo();
        $this->escribe('Confirmo');

        $this->escribe('Perfecto, gracias!');

        $this->assertNotNull($this->cita->fresh()->asistencia_confirmada_at);
    }

    public function test_mover_la_cita_borra_la_confirmacion_de_la_hora_vieja(): void
    {
        $this->cita->forceFill(['asistencia_confirmada_at' => now()])->save();

        $this->cita->update(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);

        $this->assertNull($this->cita->fresh()->asistencia_confirmada_at);
    }

    public function test_editar_otra_cosa_no_borra_la_confirmacion(): void
    {
        $this->cita->forceFill(['asistencia_confirmada_at' => now()])->save();

        $this->cita->update(['notes' => 'Trae exámenes']);

        $this->assertNotNull($this->cita->fresh()->asistencia_confirmada_at);
    }

    /** Las respuestas reales de las pacientes a los recordatorios de esa semana. */
    public function test_reconoce_las_respuestas_reales(): void
    {
        foreach (['Hola buenas tardes Confirmo Gracias', 'Confirmo', 'Ok confirmo', 'Si asistiré gracias',
            'Confirmo la cita para mañana 8:00 am', 'sí, asistiré', 'CONFIRMO'] as $texto) {
            $this->assertTrue(ConfirmacionDeAsistencia::esConfirmacionEscrita($texto), $texto);
        }

        foreach (['Buenos días gracias', 'Buenos días , podríamos correrla para las 3 pm ?', 'Ya estoy acá esperando',
            'No puedo confirmar', 'Confirmo que necesito cambiarla', 'quiero reprogramar', 'Si gracias'] as $texto) {
            $this->assertFalse(ConfirmacionDeAsistencia::esConfirmacionEscrita($texto), $texto);
        }
    }

    public function test_el_recordatorio_nuevo_pide_confirmar_y_no_lleva_botones(): void
    {
        Settings::put('reminder_template', 'recordatorio_confirmar');

        Artisan::call('appointments:send-reminders', ['--force' => true]);

        $envio = Http::recorded(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'))->first()[0]->data();

        $this->assertSame('recordatorio_confirmar', $envio['template']['name']);
        $this->assertSame(['body'], collect($envio['template']['components'])->pluck('type')->all());
        $this->assertStringContainsString('respóndenos CONFIRMO', $this->loQueSeLeContesto());
    }

    public function test_con_la_plantilla_vieja_el_historial_guarda_el_texto_viejo(): void
    {
        Settings::put('reminder_template', 'recordatorio_cita');

        Artisan::call('appointments:send-reminders', ['--force' => true]);

        $this->assertStringContainsString('Si necesitas reprogramarla', $this->loQueSeLeContesto());
    }

    public function test_sin_plantilla_el_texto_libre_pide_confirmar(): void
    {
        Settings::put('reminder_template', '');

        Artisan::call('appointments:send-reminders', ['--force' => true]);

        $this->assertStringContainsString('respóndenos CONFIRMO', $this->loQueSeLeContesto());
    }
}
