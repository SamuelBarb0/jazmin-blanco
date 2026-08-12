<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use App\Services\BotService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Días sueltos en que el consultorio no atiende.
 *
 * Distinto del horario semanal: eso cierra «los domingos», esto cierra «el 25
 * de diciembre». Lo que pidió la doctora es que Lore deje de nombrarlos.
 *
 * Hay que comprobar los DOS lados y no solo el de ofrecer, porque la paciente
 * puede pedir una fecha que nunca se le ofreció: si solo se filtrara la lista
 * de horarios, Lore le cobraría la valoración y agendaría en un día cerrado.
 */
class DiasSinAtencionTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doctora = User::factory()->create();
    }

    // ─────────────────────── Lo que ve la paciente ───────────────────────

    public function test_lore_no_ofrece_horarios_de_un_dia_cerrado(): void
    {
        // Un lunes, que abre de 8 a 18.
        $lunes = Carbon::parse('next monday', Settings::googleTimezone())->format('Y-m-d');

        // Control de que ese día SÍ se atiende. Se comprueba con el guard y no
        // pidiendo horarios porque eso llamaría a Google, que en las pruebas no
        // está configurado; el guard mira solo el horario y los días cerrados.
        $this->assertNull($this->motivo($lunes.' 10:00'), 'El día de control debería estar abierto.');

        Settings::setClosedDays([$lunes => 'Festivo']);

        $despues = $this->horarios($lunes);
        $this->assertStringContainsString('cerrado', mb_strtolower($despues));
        // Y ni una hora suelta: si se colara una, la paciente la pediría.
        $this->assertDoesNotMatchRegularExpression('/\d{1,2}:\d{2}\s*(a|p)\.?\s*m/i', $despues);
    }

    public function test_el_motivo_no_viaja_en_lo_que_lee_la_paciente(): void
    {
        $lunes = Carbon::parse('next monday', Settings::googleTimezone())->format('Y-m-d');
        Settings::setClosedDays([$lunes => 'Vacaciones en Cartagena']);

        $this->assertStringNotContainsString('Cartagena', $this->horarios($lunes));
    }

    // ─────────────────────── La pantalla del panel ───────────────────────

    public function test_la_doctora_puede_cerrar_un_dia(): void
    {
        $fecha = now()->addDays(10)->format('Y-m-d');

        $this->actingAs($this->doctora)
            ->post(route('agenda.store'), ['fecha' => $fecha, 'motivo' => 'Congreso'])
            ->assertSessionHas('success');

        $this->assertSame(['Congreso'], array_values(Settings::closedDays()));
        $this->assertTrue(Settings::isClosedOn($fecha));
    }

    public function test_puede_cerrar_un_rango_de_una_sola_vez(): void
    {
        $desde = now()->addDays(10);
        $hasta = $desde->copy()->addDays(4);

        $this->actingAs($this->doctora)->post(route('agenda.store'), [
            'fecha' => $desde->format('Y-m-d'),
            'hasta' => $hasta->format('Y-m-d'),
            'motivo' => 'Vacaciones',
        ])->assertSessionHas('success');

        // Los cinco días, extremos incluidos.
        $this->assertCount(5, Settings::closedDays());
        $this->assertTrue(Settings::isClosedOn($desde));
        $this->assertTrue(Settings::isClosedOn($hasta));
    }

    public function test_no_se_puede_cerrar_un_dia_que_ya_paso(): void
    {
        $this->actingAs($this->doctora)
            ->post(route('agenda.store'), ['fecha' => now()->subDay()->format('Y-m-d')])
            ->assertSessionHasErrors('fecha');

        $this->assertSame([], Settings::closedDays());
    }

    public function test_un_rango_al_reves_se_rechaza(): void
    {
        $this->actingAs($this->doctora)->post(route('agenda.store'), [
            'fecha' => now()->addDays(10)->format('Y-m-d'),
            'hasta' => now()->addDays(3)->format('Y-m-d'),
        ])->assertSessionHasErrors('hasta');
    }

    /**
     * Un rango abierto por error cerraría la agenda para siempre y nadie lo
     * notaría hasta que dejaran de entrar citas.
     */
    public function test_un_rango_desmedido_se_frena(): void
    {
        $this->actingAs($this->doctora)->post(route('agenda.store'), [
            'fecha' => now()->addDay()->format('Y-m-d'),
            'hasta' => now()->addYears(3)->format('Y-m-d'),
        ])->assertSessionHasErrors('hasta');

        $this->assertSame([], Settings::closedDays());
    }

    public function test_reabrir_un_dia_lo_quita_de_la_lista(): void
    {
        $fecha = now()->addDays(10)->format('Y-m-d');
        Settings::setClosedDays([$fecha => 'x']);

        $this->actingAs($this->doctora)
            ->delete(route('agenda.destroy', $fecha))
            ->assertSessionHas('success');

        $this->assertFalse(Settings::isClosedOn($fecha));
    }

    /**
     * Cerrar un día NO cancela lo que ya estaba agendado. Se avisa en pantalla
     * porque, si no, se descubriría el día que una paciente llegue a un
     * consultorio cerrado.
     */
    public function test_avisa_si_el_dia_que_se_cierra_ya_tenia_citas(): void
    {
        $fecha = now()->addDays(10);

        Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Ana',
            'starts_at' => $fecha->copy()->setTime(10, 0),
            'ends_at' => $fecha->copy()->setTime(10, 45),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->doctora)
            ->post(route('agenda.store'), ['fecha' => $fecha->format('Y-m-d')])
            ->assertSessionHas('aviso_citas', fn ($m) => str_contains((string) $m, '1 cita'));

        // Y la cita sigue ahí: cerrar el día no la toca.
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_la_pantalla_lista_los_dias_cerrados(): void
    {
        Settings::setClosedDays([now()->addDays(10)->format('Y-m-d') => 'Congreso']);

        $this->actingAs($this->doctora)
            ->get(route('agenda.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/agenda')
                ->has('dias', 1)
                ->where('dias.0.motivo', 'Congreso'));
    }

    /** El guard que decide si una hora concreta se puede agendar. No toca Google. */
    private function motivo(string $inicio): ?string
    {
        $tz = Settings::googleTimezone();
        $start = Carbon::parse($inicio, $tz);

        $metodo = new ReflectionMethod(BotService::class, 'motivoFueraDeHorario');
        $metodo->setAccessible(true);

        return $metodo->invoke(
            BotService::fromUser($this->doctora),
            $start,
            $start->copy()->addMinutes(30),
        );
    }

    /** Lo que Lore lee de la herramienta de disponibilidad, para ese día. */
    private function horarios(string $fecha): string
    {
        $metodo = new ReflectionMethod(BotService::class, 'toolAvailability');
        $metodo->setAccessible(true);

        return (string) $metodo->invoke(
            BotService::fromUser($this->doctora),
            ['fecha' => $fecha, 'dias' => 1],
        );
    }
}
