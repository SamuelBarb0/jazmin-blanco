<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Lead;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El 8 de septiembre de 2026 la doctora avisó de que a una paciente con cita al
 * día siguiente no le llegaba el recordatorio. No era el número de la paciente
 * ni el canal: la cita tenía el NOMBRE escrito en la casilla del teléfono
 * ("MARYORY FONSECA"), y su ficha del CRM estaba duplicada — el número bueno
 * vivía en la otra.
 *
 * Lo que convirtió un dedazo en una paciente sin avisar fue el `?:` de
 * `telefonoWhatsapp()`: el respaldo al teléfono del lead solo entraba si la
 * casilla estaba VACÍA, y un valor inservible no está vacío. Así que la basura
 * TAPABA el número bueno en vez de dejarle el paso.
 *
 * Y se descubrió a un día vista porque el comando de recordatorios salta esas
 * citas con un `continue` mudo: no hay error, no hay log, no hay nada.
 */
class CitaConTelefonoInservibleTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', '111111111111111');
        config()->set('services.whatsapp.api_version', 'v21.0');

        Settings::put('reminders_enabled', '1');
        Settings::put('whatsapp_bot_enabled', '1');
        Settings::put('whatsapp_test_numbers', '');
        Settings::put('reminder_template', 'recordatorio_cita');
    }

    public function test_un_texto_sin_ningun_digito_no_se_guarda_como_telefono(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        $this->actingAs($this->doctora)
            ->post(route('appointments.store'), [
                'patient_name' => 'Maryory Fonseca',
                'patient_phone' => 'MARYORY FONSECA',
                'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('patient_phone');

        $this->assertSame(0, Appointment::count(), 'La cita no debería crearse con basura en el teléfono.');
    }

    /**
     * El caso de `MILTON SERNA`: nueve dígitos escritos a mano mientras el lead
     * tenía los diez buenos. Antes se guardaba el recorte y la cita quedaba
     * muda; ahora gana el del CRM, que sí sirve para escribirle.
     */
    public function test_un_telefono_a_medias_cede_ante_el_del_paciente_del_crm(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Milton Serna',
            'phone' => '3136839698',
        ]);

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'lead_id' => $lead->id,
            'patient_name' => 'Milton Serna',
            'patient_phone' => '313683969',   // le falta un dígito
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame('3136839698', Appointment::first()->patient_phone);
    }

    public function test_la_basura_en_la_cita_no_tapa_el_numero_del_lead(): void
    {
        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Maryory Fonseca',
            'phone' => '573102595919',
        ]);

        $cita = Appointment::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'patient_name' => 'Maryory Fonseca',
            'patient_phone' => 'MARYORY FONSECA',
            'starts_at' => now()->addHours(10),
            'ends_at' => now()->addHours(11),
            'status' => 'scheduled',
        ]);

        $this->assertSame('573102595919', $cita->telefonoWhatsapp('57'));
    }

    public function test_el_recordatorio_sale_aunque_la_cita_traiga_el_telefono_roto(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Maryory Fonseca',
            'phone' => '573102595919',
        ]);

        Appointment::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'patient_name' => 'Maryory Fonseca',
            'patient_phone' => 'MARYORY FONSECA',
            // Holgado a propósito: la ventana se calcula en la zona del
            // consultorio y la cita se guarda en la de la app.
            'starts_at' => now()->addHours(10),
            'ends_at' => now()->addHours(11),
            'status' => 'scheduled',
        ]);

        $this->artisan('appointments:send-reminders --force')->assertSuccessful();

        Http::assertSent(fn ($req) => ($req->data()['to'] ?? null) === '573102595919');
    }

    /**
     * La otra mitad del arreglo: que dejar de enviar deje de ser silencioso.
     * Sin esto, la única forma de enterarse sigue siendo que la paciente no
     * aparezca a la cita.
     */
    public function test_el_resumen_diario_avisa_de_las_citas_a_las_que_no_se_puede_escribir(): void
    {
        Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Maryory Fonseca',
            'patient_phone' => 'MARYORY FONSECA',
            'starts_at' => now()->addHours(20),
            'ends_at' => now()->addHours(21),
            'status' => 'scheduled',
        ]);

        // Una sola subcadena contigua, y no dos comprobaciones encadenadas:
        // `expectsOutputToContain` consume la salida en orden, así que la
        // segunda solo miraría lo que viene DESPUÉS de lo que casó la primera.
        $this->artisan('resumen:diario --no-enviar')
            ->expectsOutputToContain('SIN teléfono válido (no recibirán recordatorio): Maryory Fonseca')
            ->assertSuccessful();
    }

    /** Una cita a la que sí se le puede escribir no debe salir en la alerta. */
    public function test_el_resumen_no_se_queja_de_las_citas_que_si_tienen_numero(): void
    {
        Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Clara Carvajal',
            'patient_phone' => '573107835915',
            'starts_at' => now()->addHours(20),
            'ends_at' => now()->addHours(21),
            'status' => 'scheduled',
        ]);

        $this->artisan('resumen:diario --no-enviar')
            ->doesntExpectOutputToContain('SIN teléfono válido')
            ->assertSuccessful();
    }
}
