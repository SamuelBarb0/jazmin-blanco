<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DeliveryFailure;
use App\Models\Lead;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El 9 de septiembre de 2026 la doctora avisó de que a una paciente con cita al
 * 12 no le llegaba nada, «y ya lo intenté tres veces». Ni el canal ni la
 * plataforma estaban rotos: la cita se guardó con el teléfono «312 3124592028»
 * —el prefijo tecleado dos veces— y nadie se enteró, por tres motivos
 * encadenados que son los que cubren estas pruebas.
 *
 * 1. Trece dígitos pasaban la validación y `phoneWithCountryCode()` los leía
 *    como «ya trae indicativo», así que el aviso salió hacia un destinatario
 *    inexistente.
 * 2. Meta lo aceptó con un 200 y lo rebotó 19 segundos después, por el webhook
 *    de acuses, con `131026 Message undeliverable`. Para entonces la pantalla
 *    ya le había dicho a la doctora «Se le avisó por WhatsApp», y el rebote
 *    murió en `laravel.log`.
 * 3. Cuando ella corrigió el número una hora más tarde, no se reenvió nada:
 *    solo se avisaba de nuevo al cambiar la FECHA.
 */
class AvisoDeCitaQueRebotaTest extends TestCase
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

    private function metaAceptaTodo(string $wamid = 'wamid.PRUEBA'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => $wamid]]], 200)]);
    }

    /** El acuse que Meta manda cuando el número no existe. */
    private function acuseFallido(string $wamid, string $para = '3123124592028'): array
    {
        return ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '111111111111111'],
            'statuses' => [[
                'id' => $wamid,
                'status' => 'failed',
                'recipient_id' => $para,
                'errors' => [[
                    'code' => 131026,
                    'title' => 'Message undeliverable',
                    'error_data' => ['details' => 'Message Undeliverable.'],
                ]],
            ]],
        ]]]]]];
    }

    // ---------------------------------------------------------------- (1)

    public function test_el_prefijo_tecleado_dos_veces_no_se_guarda(): void
    {
        $this->metaAceptaTodo();

        $this->actingAs($this->doctora)
            ->post(route('appointments.store'), [
                'patient_name' => 'Viviana Gomez',
                'patient_phone' => '312 3124592028',
                'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('patient_phone');

        $this->assertSame(0, Appointment::count());
        Http::assertNothingSent();
    }

    /**
     * El corte NO puede ser «trece dígitos»: en el CRM hay pacientes de
     * EE. UU., Panamá, Costa Rica y México con números perfectamente válidos de
     * 11 a 13 dígitos, y rechazarlos las dejaría a todas sin avisar.
     */
    public function test_los_numeros_de_otros_paises_siguen_sirviendo(): void
    {
        $this->assertSame('17146310597', Settings::phoneWithCountryCode('17146310597'), 'EE. UU.');
        $this->assertSame('50768989658', Settings::phoneWithCountryCode('50768989658'), 'Costa Rica');
        $this->assertSame('5217207920586', Settings::phoneWithCountryCode('5217207920586'), 'México');
        $this->assertSame('573124592028', Settings::phoneWithCountryCode('573124592028'), 'Colombia con 57');
        $this->assertSame('573124592028', Settings::phoneWithCountryCode('312 4592028'), 'Colombia sin 57');
    }

    public function test_se_descartan_los_numeros_imposibles(): void
    {
        $this->assertNull(Settings::phoneWithCountryCode('312 3124592028'), 'prefijo repetido');
        $this->assertNull(Settings::phoneWithCountryCode('57312459202'), 'con 57 y un dígito de menos');
        $this->assertNull(Settings::phoneWithCountryCode('5731245920281'), 'con 57 y un dígito de más');
        $this->assertNull(Settings::phoneWithCountryCode('1234567890123456'), 'más largo que E.164');
    }

    /**
     * La salida de emergencia: un «+» delante significa «es de otro país, no lo
     * interpretes». Sin ella, un móvil francés (33 6…) caería en la regla del
     * «empieza por 3 y mide más de diez» y no habría forma de escribirle.
     */
    public function test_con_mas_delante_se_respeta_el_numero_extranjero(): void
    {
        $this->assertNull(Settings::phoneWithCountryCode('33612345678'));
        $this->assertSame('33612345678', Settings::phoneWithCountryCode('+33 6 12 34 56 78'));
    }

    // ---------------------------------------------------------------- (2)

    public function test_al_avisar_se_guarda_el_codigo_del_mensaje(): void
    {
        $this->metaAceptaTodo('wamid.ABC123');

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'patient_name' => 'Viviana Gomez',
            'patient_phone' => '3124592028',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame('wamid.ABC123', Appointment::first()->notice_wamid);
    }

    public function test_el_rebote_de_meta_marca_la_cita(): void
    {
        $cita = Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Viviana Gomez',
            'patient_phone' => '3124592028',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
            'status' => 'scheduled',
            'notice_wamid' => 'wamid.ABC123',
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->acuseFallido('wamid.ABC123'))->assertOk();

        $cita->refresh();

        $this->assertNotNull($cita->notice_failed_at, 'La cita debería quedar marcada como no avisada.');
        $this->assertStringContainsString('131026', (string) $cita->notice_failure);

        // Y el registro suelto de siempre se sigue guardando.
        $this->assertSame(1, DeliveryFailure::count());
    }

    /** Un rebote de otro mensaje cualquiera no debe manchar ninguna cita. */
    public function test_un_rebote_ajeno_no_toca_las_citas(): void
    {
        $cita = Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Viviana Gomez',
            'patient_phone' => '3124592028',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
            'status' => 'scheduled',
            'notice_wamid' => 'wamid.ABC123',
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->acuseFallido('wamid.OTRO'))->assertOk();

        $this->assertNull($cita->refresh()->notice_failed_at);
    }

    // ---------------------------------------------------------------- (3)

    public function test_corregir_el_telefono_reenvia_el_aviso_y_borra_la_marca(): void
    {
        $cita = Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Viviana Gomez',
            'patient_phone' => '3009999999',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
            'status' => 'scheduled',
            'notice_wamid' => 'wamid.VIEJO',
            'notice_failed_at' => now()->subHour(),
            'notice_failure' => 'Message Undeliverable. (código 131026)',
            // Se dio por enviado al número equivocado: la paciente de este
            // número nuevo no ha recibido nada.
            'reminder_24h_sent_at' => now()->subHour(),
        ]);

        $this->metaAceptaTodo('wamid.NUEVO');

        $this->actingAs($this->doctora)->put(route('appointments.update', $cita), [
            'patient_name' => 'Viviana Gomez',
            'patient_phone' => '312 4592028',
            'starts_at' => $cita->starts_at->format('Y-m-d H:i:s'),
        ]);

        Http::assertSent(fn ($req) => ($req->data()['to'] ?? null) === '573124592028');

        $cita->refresh();
        $this->assertSame('wamid.NUEVO', $cita->notice_wamid);
        $this->assertNull($cita->notice_failed_at, 'La marca roja debe irse al reenviar.');
        $this->assertNull($cita->reminder_24h_sent_at, 'El recordatorio debe poder volver a salir al número nuevo.');
    }

    /**
     * Y al revés: retocar la forma del número —un espacio, un guion— no es
     * cambiarlo. Sin esta distinción, cada vez que la doctora abriera y
     * guardara una cita la paciente recibiría otro «tu cita quedó agendada».
     */
    public function test_un_retoque_de_forma_no_vuelve_a_escribirle_a_nadie(): void
    {
        $cita = Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Viviana Gomez',
            'patient_phone' => '3124592028',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
            'status' => 'scheduled',
        ]);

        $this->metaAceptaTodo();

        $this->actingAs($this->doctora)->put(route('appointments.update', $cita), [
            'patient_name' => 'Viviana Gomez',
            'patient_phone' => '312-459-2028',
            'starts_at' => $cita->starts_at->format('Y-m-d H:i:s'),
        ]);

        Http::assertNothingSent();
    }

    /**
     * El caso de la propia Viviana de punta a punta: el lead se creó con el
     * número malo y ahí sigue. La cita tiene el bueno, y es el que manda.
     */
    public function test_el_numero_bueno_de_la_cita_gana_al_malo_del_lead(): void
    {
        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Viviana Gomez',
            'phone' => '312 3124592028',
        ]);

        $cita = Appointment::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'patient_name' => 'VIVIANA GOMEZ',
            'patient_phone' => '312 4592028',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
            'status' => 'scheduled',
        ]);

        $this->assertSame('573124592028', $cita->telefonoWhatsapp('57'));
    }
}
