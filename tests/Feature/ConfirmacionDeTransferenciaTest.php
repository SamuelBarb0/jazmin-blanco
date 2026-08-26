<?php

namespace Tests\Feature;

use App\Mail\CitaParaLaPaciente;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El aviso que faltaba al verificar una transferencia (reporte de la doctora,
 * 26-ago-2026: «paciente pagó, di confirmado pago y la plataforma no envió
 * mensaje de confirmación de cita y me tocó hacerlo manual»).
 *
 * Cuando la paciente paga por transferencia, el asistente aparta el cupo y le
 * promete literalmente «apenas se refleje el pago te confirmo la cita». Esa
 * promesa no la cumplía nadie: `verifyTransfer()` limpiaba la marca, tocaba
 * Google y devolvía un mensaje a la pantalla de la doctora — a la paciente no
 * le salía absolutamente nada, y la doctora ni siquiera podía saber que el
 * sistema no lo había hecho.
 */
class ConfirmacionDeTransferenciaTest extends TestCase
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
     * Una cita apartada a la espera de la transferencia, con el chat abierto
     * (la paciente escribió hace poco), que es el caso del reporte.
     */
    private function citaPendiente(): Appointment
    {
        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Yaneth Gómez',
            'phone' => '573001112233',
            'email' => 'yaneth@example.test',
        ]);

        $conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => 'Yaneth Gómez',
        ]);

        Message::create([
            'conversation_id' => $conversacion->id,
            'role' => 'user',
            'content' => 'Ya hice la transferencia',
        ]);

        return Appointment::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'patient_name' => 'Yaneth Gómez',
            'patient_phone' => '573001112233',
            'patient_email' => 'yaneth@example.test',
            'starts_at' => now()->addDays(3)->setTime(8, 0),
            'ends_at' => now()->addDays(3)->setTime(9, 0),
            'status' => 'scheduled',
            'transfer_pending_at' => now()->subHour(),
        ]);
    }

    public function test_al_verificar_la_transferencia_se_le_avisa_por_whatsapp(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);
        Mail::fake();

        $cita = $this->citaPendiente();

        $this->actingAs($this->doctora)
            ->patch(route('appointments.verify-transfer', $cita))
            ->assertRedirect();

        Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            return ($cuerpo['type'] ?? '') === 'text'
                && str_contains($cuerpo['text']['body'] ?? '', 'Confirmamos tu pago');
        });
    }

    public function test_el_aviso_queda_en_el_historial_del_chat(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);
        Mail::fake();

        $cita = $this->citaPendiente();

        $this->actingAs($this->doctora)->patch(route('appointments.verify-transfer', $cita));

        // Si no queda escrito, la doctora abre la bandeja, no ve nada y vuelve
        // a escribirle a mano: exactamente lo que reportó.
        $this->assertDatabaseHas('messages', ['sent_by' => 'bot', 'role' => 'assistant']);
        $this->assertTrue(Message::where('content', 'like', '%Confirmamos tu pago%')->exists());
    }

    public function test_tambien_le_llega_el_correo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);
        Mail::fake();

        $cita = $this->citaPendiente();

        $this->actingAs($this->doctora)->patch(route('appointments.verify-transfer', $cita));

        Mail::assertSent(CitaParaLaPaciente::class, fn ($mail) => $mail->tipo === 'confirmada'
            && $mail->appointment->is($cita));
    }

    public function test_la_cita_deja_de_estar_pendiente_y_queda_confirmada(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);
        Mail::fake();

        $cita = $this->citaPendiente();

        $this->actingAs($this->doctora)->patch(route('appointments.verify-transfer', $cita));

        $cita->refresh();

        $this->assertNull($cita->transfer_pending_at);
        $this->assertSame('confirmed', $cita->status);
    }

    /**
     * La doctora tiene que saber en el momento si el aviso salió o no. Antes
     * agendaba a ciegas y esa fue la mitad del problema: no es que fallara, es
     * que no había forma de enterarse.
     */
    public function test_sin_telefono_se_le_dice_a_la_doctora_que_no_se_pudo_avisar(): void
    {
        Mail::fake();

        $cita = Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Sin número',
            'starts_at' => now()->addDays(3)->setTime(8, 0),
            'ends_at' => now()->addDays(3)->setTime(9, 0),
            'status' => 'scheduled',
            'transfer_pending_at' => now()->subHour(),
        ]);

        $this->actingAs($this->doctora)
            ->patch(route('appointments.verify-transfer', $cita))
            ->assertSessionHas('success', fn ($mensaje) => str_contains($mensaje, 'Sin teléfono'));
    }

    /** Sin transferencia pendiente no se avisa nada: no hay nada que confirmar. */
    public function test_una_cita_sin_transferencia_pendiente_no_dispara_avisos(): void
    {
        Http::fake();
        Mail::fake();

        $cita = $this->citaPendiente();
        $cita->forceFill(['transfer_pending_at' => null])->save();

        $this->actingAs($this->doctora)->patch(route('appointments.verify-transfer', $cita));

        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
