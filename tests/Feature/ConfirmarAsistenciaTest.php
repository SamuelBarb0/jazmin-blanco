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
 * registraba.
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

        $this->fakeHttp();

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

    private function fakeHttp(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Con gusto te ayudo 😊']],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);
    }

    /** @param  array<string,mixed>  $mensaje */
    private function llega(array $mensaje, string $from = self::TELEFONO): void
    {
        $this->postJson('/api/webhooks/whatsapp', [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => self::LINEA],
                        'contacts' => [['profile' => ['name' => 'Martha']]],
                        'messages' => [array_merge(['id' => 'wamid.'.uniqid(), 'from' => $from], $mensaje)],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    private function escribe(string $texto): void
    {
        $this->llega(['type' => 'text', 'text' => ['body' => $texto]]);
    }

    private function tocaBoton(string $payload, string $texto = 'Confirmo mi asistencia', string $from = self::TELEFONO): void
    {
        $this->llega(['type' => 'button', 'button' => ['payload' => $payload, 'text' => $texto]], $from);
    }

    private function yaSeLeRecordo(): void
    {
        $this->chat->messages()->create([
            'role' => 'assistant',
            'content' => 'Hola Martha 👋 Te recordamos tu cita mañana (miércoles 23 de septiembre a las 10:00 am) en el consultorio. Si necesitas reprogramarla, respóndenos por este chat.',
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

    public function test_el_boton_confirma_la_cita_y_contesta_sin_pasar_por_lore(): void
    {
        $this->tocaBoton('CONFIRMAR_CITA:'.$this->cita->id);

        $this->assertNotNull($this->cita->fresh()->asistencia_confirmada_at);
        $this->assertStringContainsString('Tu asistencia quedó confirmada', $this->loQueSeLeContesto());
        $this->assertStringContainsString('¡Gracias, Martha!', $this->loQueSeLeContesto());
        $this->assertFalse($this->seLlamoALore());
    }

    public function test_el_boton_de_otra_persona_no_confirma_nada(): void
    {
        $this->tocaBoton('CONFIRMAR_CITA:'.$this->cita->id, 'Confirmo mi asistencia', '573009998877');

        $this->assertNull($this->cita->fresh()->asistencia_confirmada_at);
    }

    public function test_el_boton_de_reprogramar_va_a_lore_y_no_confirma(): void
    {
        $this->tocaBoton('REPROGRAMAR_CITA:'.$this->cita->id, 'Necesito reprogramar');

        $this->assertNull($this->cita->fresh()->asistencia_confirmada_at);
        $this->assertTrue($this->seLlamoALore());
    }

    public function test_un_confirmo_escrito_tras_el_recordatorio_confirma(): void
    {
        $this->yaSeLeRecordo();

        $this->escribe('Hola buenas tardes Confirmo Gracias');

        $this->assertNotNull($this->cita->fresh()->asistencia_confirmada_at);
        $this->assertFalse($this->seLlamoALore());
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
        $this->chat->forceFill(['bot_enabled' => false, 'bot_paused_manually' => true])->save();

        $this->tocaBoton('CONFIRMAR_CITA:'.$this->cita->id);

        $this->assertNotNull($this->cita->fresh()->asistencia_confirmada_at);
        $this->assertSame(0, $this->chat->messages()->where('role', 'assistant')->count());
    }

    /** Las respuestas reales de las pacientes a los recordatorios de esa semana. */
    public function test_reconoce_las_respuestas_reales(): void
    {
        foreach (['Hola buenas tardes Confirmo Gracias', 'Confirmo', 'Ok confirmo', 'Si asistiré gracias',
            'Confirmo la cita para mañana 8:00 am', 'sí, asistiré'] as $texto) {
            $this->assertTrue(ConfirmacionDeAsistencia::esConfirmacionEscrita($texto), $texto);
        }

        foreach (['Buenos días gracias', 'Buenos días , podríamos correrla para las 3 pm ?', 'Ya estoy acá esperando',
            'No puedo confirmar', 'Confirmo que necesito cambiarla', 'quiero reprogramar', 'Si gracias'] as $texto) {
            $this->assertFalse(ConfirmacionDeAsistencia::esConfirmacionEscrita($texto), $texto);
        }
    }

    public function test_el_recordatorio_con_la_plantilla_nueva_lleva_los_botones_con_la_cita(): void
    {
        Settings::put('reminder_template', 'confirmar_cita');

        Artisan::call('appointments:send-reminders', ['--force' => true]);

        $envio = Http::recorded(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'))->first()[0]->data();

        $this->assertSame('confirmar_cita', $envio['template']['name']);
        $botones = collect($envio['template']['components'])->where('type', 'button')->values();
        $this->assertCount(2, $botones);
        $this->assertSame('CONFIRMAR_CITA:'.$this->cita->id, $botones[0]['parameters'][0]['payload']);
        $this->assertSame('REPROGRAMAR_CITA:'.$this->cita->id, $botones[1]['parameters'][0]['payload']);

        // El historial guarda el texto que de verdad le llegó.
        $this->assertStringContainsString('Por favor confirma tu asistencia', $this->loQueSeLeContesto());
    }

    public function test_la_plantilla_vieja_no_lleva_botones(): void
    {
        Settings::put('reminder_template', 'recordatorio_cita');

        Artisan::call('appointments:send-reminders', ['--force' => true]);

        $envio = Http::recorded(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'))->first()[0]->data();

        $this->assertSame([], collect($envio['template']['components'])->where('type', 'button')->all());
    }

    public function test_sin_plantilla_el_texto_pide_confirmar(): void
    {
        Settings::put('reminder_template', '');

        Artisan::call('appointments:send-reminders', ['--force' => true]);

        $this->assertStringContainsString('respóndenos CONFIRMO', $this->loQueSeLeContesto());
    }
}
