<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lo que pidió la doctora: al agendar, la paciente debe recibir «tu cita quedó
 * agendada» — ese es el PRIMER aviso, antes de los recordatorios de 24 h y 2 h.
 *
 * No se enviaba nada: `AppointmentController` no tocaba WhatsApp en ningún
 * punto. Y los recordatorios tampoco salían, porque las citas nacían sin
 * teléfono aunque la paciente elegida del CRM sí lo tuviera (de 103 citas en
 * producción, solo 22 tenían número propio).
 */
class AvisoCitaAgendadaTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', '111111111111111');

        Settings::put('whatsapp_bot_enabled', '1');
        Settings::put('whatsapp_test_numbers', '');
        Settings::put('reminder_template', 'recordatorio_cita');
    }

    /**
     * Meta acepta todo. No va en `setUp()` a propósito: `Http::fake()` ACUMULA
     * stubs y gana el primero que encaja, así que uno global aquí impediría que
     * una prueba declare el suyo para simular un fallo.
     */
    private function metaAceptaTodo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);
    }

    public function test_la_cita_hereda_el_telefono_del_paciente_del_crm(): void
    {
        $this->metaAceptaTodo();

        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Ana Devide',
            'phone' => '573001112233',
        ]);

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana Devide',
            // La doctora no reescribe el teléfono: ya eligió a la paciente.
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $this->assertSame('573001112233', Appointment::firstOrFail()->patient_phone);
    }

    public function test_sin_telefono_avisa_a_la_doctora_de_que_nadie_recibira_nada(): void
    {
        $this->metaAceptaTodo();

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'patient_name' => 'Paciente Sin Numero',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Sin teléfono')
            && str_contains($m, 'tampoco recibirá recordatorios'));

        Http::assertNothingSent();
    }

    public function test_con_la_ventana_abierta_se_manda_texto_libre(): void
    {
        $this->metaAceptaTodo();

        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana', 'phone' => '573001112233']);
        $conv = Conversation::create([
            'user_id' => $this->doctora->id, 'lead_id' => $lead->id,
            'channel' => 'whatsapp', 'title' => 'WhatsApp · Ana',
        ]);
        // Mensaje entrante reciente = ventana de 24 h abierta.
        Message::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'hola']);

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Se le avisó por WhatsApp'));

        Http::assertSent(fn ($req) => ($req->data()['type'] ?? null) === 'text'
            && str_contains($req->data()['text']['body'], 'quedó agendada'));
    }

    public function test_sin_ventana_abierta_se_usa_la_plantilla_de_confirmacion(): void
    {
        $this->metaAceptaTodo();

        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana', 'phone' => '573001112233']);

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'por plantilla'));

        Http::assertSent(fn ($req) => ($req->data()['type'] ?? null) === 'template'
            && $req->data()['template']['name'] === 'cita_agendada');
    }

    /**
     * Mientras Meta tenga `cita_agendada` en PENDIENTE su envío falla. No debe
     * quedarse sin avisar: cae en la de recordatorio, que sí está aprobada.
     */
    public function test_si_la_de_confirmacion_no_esta_aprobada_cae_en_la_de_recordatorio(): void
    {
        Http::fake(function ($request) {
            $nombre = $request->data()['template']['name'] ?? null;

            return $nombre === 'cita_agendada'
                ? Http::response(['error' => ['message' => 'Template not found or not approved']], 400)
                : Http::response(['messages' => [['id' => 'wamid.x']]], 200);
        });

        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana', 'phone' => '573001112233']);

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'aún no está aprobada'));

        Http::assertSent(fn ($req) => ($req->data()['template']['name'] ?? null) === 'cita_agendada');
        Http::assertSent(fn ($req) => ($req->data()['template']['name'] ?? null) === 'recordatorio_cita');
    }

    public function test_con_los_mensajes_en_pausa_no_se_envia_nada(): void
    {
        $this->metaAceptaTodo();

        Settings::put('whatsapp_bot_enabled', '0');
        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana', 'phone' => '573001112233']);

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'en pausa'));

        Http::assertNothingSent();
    }
}
