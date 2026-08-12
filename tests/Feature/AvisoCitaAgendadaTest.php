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

    /**
     * Reprogramar dejaba a la paciente fuera por partida doble: no se le avisaba
     * del cambio Y las marcas de recordatorio seguían apuntando a la fecha
     * vieja, así que a quien ya había recibido el aviso de 24 h no le llegaba
     * NINGUNO para la fecha nueva.
     */
    public function test_al_mover_la_cita_se_avisa_del_cambio(): void
    {
        $this->metaAceptaTodo();

        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana', 'phone' => '573001112233']);
        $cita = $this->citaDe($lead, now()->addDays(3));

        $this->actingAs($this->doctora)->patch(route('appointments.update', $cita), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDays(8)->format('Y-m-d H:i:s'),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Se le avisó del cambio'));

        Http::assertSent(fn ($req) => ($req->data()['template']['name'] ?? null) === 'cita_reprogramada');
    }

    public function test_al_mover_la_cita_se_borran_las_marcas_de_recordatorio(): void
    {
        $this->metaAceptaTodo();

        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana', 'phone' => '573001112233']);
        $cita = $this->citaDe($lead, now()->addDays(3));
        // Ya se le avisó de la fecha VIEJA.
        $cita->forceFill(['reminder_24h_sent_at' => now(), 'reminder_2h_sent_at' => now()])->save();

        $this->actingAs($this->doctora)->patch(route('appointments.update', $cita), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDays(8)->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $cita->refresh();
        $this->assertNull($cita->reminder_24h_sent_at);
        $this->assertNull($cita->reminder_2h_sent_at);
    }

    /**
     * Editar el nombre o las notas no es reprogramar: si cualquier guardado
     * avisara, a la paciente le llegaría un WhatsApp por cada corrección.
     */
    public function test_editar_sin_mover_la_hora_no_avisa_ni_borra_las_marcas(): void
    {
        $this->metaAceptaTodo();

        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana', 'phone' => '573001112233']);
        $cuando = now()->addDays(3);
        $cita = $this->citaDe($lead, $cuando);
        $cita->forceFill(['reminder_24h_sent_at' => now()])->save();

        $this->actingAs($this->doctora)->patch(route('appointments.update', $cita), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana',
            'starts_at' => $cuando->format('Y-m-d H:i:s'),
            'notes' => 'Llega 10 minutos antes',
        ])->assertSessionHas('success', fn ($m) => ! str_contains($m, 'avisó'));

        Http::assertNothingSent();
        $this->assertNotNull($cita->refresh()->reminder_24h_sent_at);
    }

    /**
     * Mientras Meta tenga `cita_reprogramada` en PENDIENTE, el aviso del cambio
     * no puede perderse: cae en la de confirmación, que ya está aprobada y al
     * menos lleva la fecha NUEVA.
     */
    public function test_si_la_de_reprogramada_no_esta_aprobada_cae_en_la_de_confirmacion(): void
    {
        Http::fake(function ($request) {
            $nombre = $request->data()['template']['name'] ?? null;

            return $nombre === 'cita_reprogramada'
                ? Http::response(['error' => ['message' => 'Template not found or not approved']], 400)
                : Http::response(['messages' => [['id' => 'wamid.x']]], 200);
        });

        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana', 'phone' => '573001112233']);
        $cita = $this->citaDe($lead, now()->addDays(3));

        $this->actingAs($this->doctora)->patch(route('appointments.update', $cita), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDays(8)->format('Y-m-d H:i:s'),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'cita_agendada')
            && str_contains($m, 'aún no está aprobada'));

        Http::assertSent(fn ($req) => ($req->data()['template']['name'] ?? null) === 'cita_reprogramada');
        Http::assertSent(fn ($req) => ($req->data()['template']['name'] ?? null) === 'cita_agendada');
    }

    private function citaDe(Lead $lead, $cuando): Appointment
    {
        return Appointment::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'patient_name' => $lead->name,
            'patient_phone' => $lead->phone,
            'starts_at' => $cuando,
            'ends_at' => (clone $cuando)->addMinutes(45),
            'status' => 'scheduled',
        ]);
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

    // ─────────────────────── El aviso por correo ───────────────────────

    /**
     * El correo va APARTE del WhatsApp, no en su lugar: WhatsApp necesita
     * teléfono y, fuera de la ventana de 24 h, plantilla aprobada por Meta; el
     * correo solo necesita dirección. Para la mayoría de las pacientes —de 102
     * citas solo 31 tienen teléfono— este correo es el único aviso que llega.
     */
    public function test_al_agendar_se_le_manda_el_correo_a_la_paciente(): void
    {
        $this->metaAceptaTodo();
        Mail::fake();

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'patient_name' => 'Ana Gómez',
            'patient_email' => 'ana@ejemplo.com',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'ana@ejemplo.com'));

        Mail::assertSent(CitaParaLaPaciente::class, fn ($mail) => $mail->hasTo('ana@ejemplo.com')
            && $mail->tipo === 'agendada');
    }

    public function test_el_correo_se_hereda_del_paciente_del_crm(): void
    {
        $this->metaAceptaTodo();
        Mail::fake();

        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Ana',
            'phone' => '573001112233',
            'email' => 'ana.lead@ejemplo.com',
        ]);

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ]);

        Mail::assertSent(CitaParaLaPaciente::class, fn ($mail) => $mail->hasTo('ana.lead@ejemplo.com'));
    }

    public function test_sin_correo_no_se_manda_nada_ni_se_rompe_el_guardado(): void
    {
        $this->metaAceptaTodo();
        Mail::fake();

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'patient_name' => 'Ana',
            'patient_phone' => '573001112233',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertSessionHas('success');

        Mail::assertNothingSent();
    }

    public function test_al_mover_la_cita_el_correo_dice_que_fue_reprogramada(): void
    {
        $this->metaAceptaTodo();
        Mail::fake();

        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Ana',
            'phone' => '573001112233',
        ]);
        $cita = $this->citaDe($lead, now()->addDays(5));
        $cita->update(['patient_email' => 'ana@ejemplo.com']);

        $this->actingAs($this->doctora)->patch(route('appointments.update', $cita), [
            'lead_id' => $lead->id,
            'patient_name' => $cita->patient_name,
            'patient_email' => 'ana@ejemplo.com',
            'starts_at' => now()->addDays(6)->format('Y-m-d H:i:s'),
        ]);

        Mail::assertSent(CitaParaLaPaciente::class, fn ($mail) => $mail->tipo === 'reprogramada');
    }

    /**
     * El otro fallo que destapó la auditoría de producción: el aviso al
     * agendar mandaba el número CRUDO. Meta exige indicativo, así que un móvil
     * colombiano de 10 dígitos se aceptaba con 200 y rebotaba después con
     * `131026` — 11 confirmaciones perdidas en cinco días, sin traza visible.
     * El comando de recordatorios sí normalizaba, de ahí que a la misma
     * paciente le llegara el recordatorio pero nunca la confirmación.
     */
    public function test_al_numero_se_le_pone_el_indicativo_antes_de_enviar(): void
    {
        $this->metaAceptaTodo();

        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Ana',
            'phone' => '3212521422',   // como está guardado en produccion: sin el 57
        ]);
        $conv = Conversation::create([
            'user_id' => $this->doctora->id, 'lead_id' => $lead->id,
            'channel' => 'whatsapp', 'title' => 'WhatsApp · Ana',
        ]);
        Message::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'hola']);

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'lead_id' => $lead->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ]);

        Http::assertSent(fn ($req) => ($req->data()['to'] ?? null) === '573212521422');
    }

    public function test_un_numero_demasiado_corto_no_se_envia(): void
    {
        $this->metaAceptaTodo();

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'patient_name' => 'Gloria',
            'patient_phone' => '321250875',   // 9 digitos: existe en produccion
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Sin teléfono'));

        Http::assertNothingSent();
    }

    /**
     * La prueba que faltaba, y que habría cazado el fallo: `starts_at` guarda
     * la hora de PARED del consultorio, pero `app.timezone` es UTC, así que
     * Laravel la lee etiquetada como UTC. Con `->tz()` —lo que hacía el
     * controlador— una cita de las 2:00 p. m. se le anunciaba a la paciente a
     * las 9:00 a. m.; hay que reetiquetar con `shiftTimezone()`, no convertir.
     *
     * Se comprueban los DOS canales a la vez porque el fallo estaba justo en
     * que no coincidían: los recordatorios lo hacían bien y el aviso al
     * agendar, mal.
     */
    public function test_la_hora_que_se_le_dice_a_la_paciente_es_la_del_consultorio(): void
    {
        $this->metaAceptaTodo();
        Mail::fake();

        Settings::put('google_timezone', 'America/Bogota');

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'patient_name' => 'Ana',
            'patient_email' => 'ana@ejemplo.com',
            'patient_phone' => '573001112233',
            // 2:00 p. m. en el consultorio.
            'starts_at' => now()->addDays(3)->setTime(14, 0)->format('Y-m-d H:i:s'),
        ]);

        // Por correo.
        Mail::assertSent(CitaParaLaPaciente::class, function ($mail) {
            $html = $mail->render();

            return str_contains($html, '2:00 p. m.') && ! str_contains($html, '9:00 a. m.');
        });

        // Y por WhatsApp, que tiene que decir exactamente lo mismo.
        Http::assertSent(function ($req) {
            $cuerpo = json_encode($req->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($cuerpo, '2:00 p. m.') && ! str_contains($cuerpo, '9:00 a. m.');
        });
    }
}
