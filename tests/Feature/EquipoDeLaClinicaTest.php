<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Varias personas trabajando sobre la misma clínica.
 *
 * Antes había una sola cuenta y todo colgaba de ella: crear un usuario nuevo no
 * daba acceso a nada, daba un sistema vacío. Ahora `users.cuenta_id` dice de qué
 * clínica es cada persona, y las relaciones del modelo leen por ahí — que es lo
 * que hace que las 68 consultas repartidas por la app sigan escritas igual y
 * devuelvan los datos de la clínica.
 */
class EquipoDeLaClinicaTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    private User $ingeniero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create(['name' => 'Dra. Jasmin Blanco']);

        $this->ingeniero = User::factory()->create([
            'name' => 'Ingeniero invitado',
            'cuenta_id' => $this->doctora->id,
            'es_propietario' => false,
        ]);
    }

    /** Quien se crea sin clínica es dueño de la suya: el comportamiento de siempre. */
    public function test_una_cuenta_nueva_es_dueña_de_si_misma(): void
    {
        $this->doctora->refresh();

        $this->assertSame($this->doctora->id, $this->doctora->cuenta_id);
        $this->assertTrue($this->doctora->es_propietario);
        $this->assertTrue($this->doctora->puedeAdministrarEquipo());
    }

    /** Lo que no funcionaba: el invitado entraba y veía un sistema vacío. */
    public function test_el_equipo_ve_las_pacientes_de_la_clinica(): void
    {
        Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana Devide', 'phone' => '573001112233']);
        Lead::create(['user_id' => $this->doctora->id, 'name' => 'Flor Elena', 'phone' => '573004445566']);

        $this->assertSame(2, $this->ingeniero->leads()->count());
        $this->assertSame('Ana Devide', $this->ingeniero->leads()->orderBy('id')->first()->name);
    }

    public function test_el_equipo_ve_la_agenda_y_los_servicios(): void
    {
        Service::create(['user_id' => $this->doctora->id, 'name' => 'Implante capilar']);
        Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        $this->assertSame(1, $this->ingeniero->services()->count());
        $this->assertSame(1, $this->ingeniero->appointments()->count());
    }

    /** La pantalla de agenda es la prueba de que las 68 consultas siguen sirviendo. */
    public function test_el_equipo_abre_la_agenda_y_ve_las_citas_de_la_clinica(): void
    {
        Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Paciente de la clínica',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->ingeniero)
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('appointments.0.patient_name', 'Paciente de la clínica'));
    }

    /**
     * Lo que crea el equipo queda de la CLÍNICA, no de la persona: si no, se
     * perdería en cuanto a esa persona se le quitara el acceso.
     */
    public function test_lo_que_crea_el_equipo_queda_de_la_clinica(): void
    {
        $lead = $this->ingeniero->leads()->create(['name' => 'Paciente nueva']);

        $this->assertSame($this->doctora->id, $lead->user_id);
        $this->assertSame(1, $this->doctora->leads()->count());
    }

    /** Otra clínica no se ve, y esta es la prueba que impide el desastre. */
    public function test_una_clinica_no_ve_los_datos_de_otra(): void
    {
        $otraDoctora = User::factory()->create();
        Lead::create(['user_id' => $otraDoctora->id, 'name' => 'Paciente ajena']);
        Lead::create(['user_id' => $this->doctora->id, 'name' => 'Paciente propia']);

        $this->assertSame(1, $this->ingeniero->leads()->count());
        $this->assertSame('Paciente propia', $this->ingeniero->leads()->first()->name);
        $this->assertSame(1, $otraDoctora->leads()->count());
    }

    public function test_el_equipo_se_lista_completo_desde_cualquiera_de_sus_miembros(): void
    {
        $this->assertSame(2, $this->doctora->equipo()->count());
        $this->assertSame(2, $this->ingeniero->equipo()->count());
    }

    /** Dar y quitar accesos es cosa de la dueña. */
    public function test_el_invitado_no_administra_el_equipo(): void
    {
        $this->assertFalse($this->ingeniero->puedeAdministrarEquipo());
        $this->assertTrue($this->doctora->puedeAdministrarEquipo());
    }

    /**
     * EL AGUJERO QUE DEJÓ LA FUNCIÓN DE EQUIPO (reporte de la doctora,
     * 26-ago-2026: «tampoco puedo dar respuesta a los mensajes en el
     * computador, solo en el celular»).
     *
     * Las relaciones del modelo se pasaron a `cuenta_id`, pero las
     * comprobaciones de permiso de los controladores se quedaron comparando
     * contra `$request->user()->id`. Resultado: el equipo VEÍA la bandeja
     * entera y recibía un 403 al abrir cualquier chat — la lista se cargaba,
     * el clic no hacía nada, y desde fuera parecía que el CRM estaba roto.
     */
    public function test_el_equipo_puede_abrir_un_chat_de_la_clinica(): void
    {
        $conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'channel' => 'whatsapp',
            'title' => 'Marcela',
        ]);

        $this->actingAs($this->ingeniero)
            ->get(route('inbox.show', $conversacion))
            ->assertOk();
    }

    public function test_el_equipo_puede_pausar_al_asistente_en_un_chat(): void
    {
        $conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'channel' => 'whatsapp',
            'title' => 'Marcela',
            'bot_enabled' => true,
        ]);

        $this->actingAs($this->ingeniero)
            ->patch(route('inbox.toggle', $conversacion))
            ->assertRedirect();

        $this->assertFalse($conversacion->fresh()->bot_enabled);
    }

    /** Lo mismo en la agenda: verificar una transferencia también daba 403. */
    public function test_el_equipo_puede_tocar_una_cita_de_la_clinica(): void
    {
        $cita = Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->ingeniero)
            ->delete(route('appointments.destroy', $cita))
            ->assertRedirect();

        $this->assertDatabaseMissing('appointments', ['id' => $cita->id]);
    }

    /** Y el 403 sigue en pie para quien NO es de la clínica. */
    public function test_una_clinica_ajena_sigue_recibiendo_403(): void
    {
        $otraDoctora = User::factory()->create();
        $conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'channel' => 'whatsapp',
            'title' => 'Marcela',
        ]);

        $this->actingAs($otraDoctora)
            ->get(route('inbox.show', $conversacion))
            ->assertForbidden();
    }
}
