<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BotService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El almuerzo de la doctora es tiempo suyo, no un hueco.
 *
 * Lore ofrecía las 12:00 como cualquier otra hora porque el horario de
 * atención era UNA franja continua (8:00–18:00) sin concepto de descanso. Y
 * peor: `createBooking()` no comprobaba el horario en absoluto —solo Google y
 * los apartados—, así que una paciente que pidiera las 12:30 se agendaba
 * igual aunque nunca se le hubiera ofrecido esa hora.
 */
class AgendaHorarioTest extends TestCase
{
    use RefreshDatabase;

    /** Un miércoles cualquiera, para no depender del día en que corra el test. */
    private const MIERCOLES = '2026-08-12';

    private const SABADO = '2026-08-15';

    private const DOMINGO = '2026-08-16';

    public function test_el_descanso_por_defecto_es_el_almuerzo_de_todos_los_dias_que_abre(): void
    {
        $descansos = Settings::scheduleBreaks();

        foreach ([1, 2, 3, 4, 5, 6] as $diaQueAbre) {
            $this->assertSame(['12:00', '13:00'], $descansos[$diaQueAbre]);
        }

        // Domingo cerrado: no hay jornada de la que descansar.
        $this->assertNull($descansos[0]);
    }

    public function test_no_se_puede_agendar_dentro_del_almuerzo(): void
    {
        // Empieza justo al abrir el descanso.
        $this->assertRechazado(self::MIERCOLES.' 12:00', 30, 'descanso');
        // Empieza en mitad del descanso.
        $this->assertRechazado(self::MIERCOLES.' 12:30', 30, 'descanso');
        // Empieza ANTES pero se mete dentro: 11:45 + 45 min = 12:30.
        $this->assertRechazado(self::MIERCOLES.' 11:45', 45, 'descanso');
    }

    public function test_los_bordes_del_almuerzo_siguen_siendo_agendables(): void
    {
        // Termina justo cuando empieza el descanso.
        $this->assertAceptado(self::MIERCOLES.' 11:30', 30);
        // Empieza justo cuando termina.
        $this->assertAceptado(self::MIERCOLES.' 13:00', 30);
    }

    public function test_no_se_puede_agendar_fuera_de_la_jornada(): void
    {
        $this->assertRechazado(self::MIERCOLES.' 07:00', 30, 'fuera del horario');
        $this->assertRechazado(self::MIERCOLES.' 21:00', 30, 'fuera del horario');
        // Cabe el comienzo pero no el procedimiento entero: 17:45 + 45 = 18:30.
        $this->assertRechazado(self::MIERCOLES.' 17:45', 45, 'fuera del horario');
    }

    public function test_no_se_puede_agendar_un_dia_cerrado(): void
    {
        $this->assertRechazado(self::DOMINGO.' 10:00', 30, 'no atiende');
    }

    public function test_el_sabado_tambien_almuerza_y_eso_lo_deja_en_media_manana(): void
    {
        // El sábado abre 9:00–13:00 y el almuerzo ocupa 12:00–13:00, así que en
        // la práctica la jornada termina a las 12:00. Se comprueba entero para
        // que nadie «arregle» el solape sin darse cuenta de esta consecuencia.
        $this->assertAceptado(self::SABADO.' 11:30', 30);
        $this->assertRechazado(self::SABADO.' 12:00', 30, 'descanso');
        $this->assertRechazado(self::SABADO.' 12:30', 30, 'descanso');
        // Y más allá de las 13:00 ya no cabe ni aunque no hubiera descanso.
        $this->assertRechazado(self::SABADO.' 12:45', 30, 'fuera del horario');
    }

    // ───────────────── Días sueltos cerrados a mano ─────────────────

    public function test_un_dia_cerrado_a_mano_no_se_puede_agendar(): void
    {
        // Un miércoles normal, en el que sí se atiende.
        $this->assertAceptado(self::MIERCOLES.' 10:00', 30);

        Settings::setClosedDays([self::MIERCOLES => 'Congreso']);

        // Y ahora no, aunque el horario semanal siga diciendo que abre.
        $this->assertRechazado(self::MIERCOLES.' 10:00', 30, 'no atiende el');
    }

    /**
     * El motivo es para la clínica. Decirle a la paciente «cerrado por
     * vacaciones» solo invita a negociar, y de paso cuenta cosas de la doctora
     * que no le corresponden a nadie más.
     */
    public function test_el_motivo_del_cierre_no_se_le_cuenta_a_la_paciente(): void
    {
        Settings::setClosedDays([self::MIERCOLES => 'Cirugía de la doctora']);

        $motivo = $this->motivo(self::MIERCOLES.' 10:00', 30);

        $this->assertStringNotContainsString('Cirugía', (string) $motivo);
    }

    public function test_reabrir_un_dia_lo_devuelve_a_la_agenda(): void
    {
        Settings::setClosedDays([self::MIERCOLES => '']);
        $this->assertRechazado(self::MIERCOLES.' 10:00', 30, 'no atiende el');

        Settings::setClosedDays([]);
        $this->assertAceptado(self::MIERCOLES.' 10:00', 30);
    }

    public function test_las_fechas_ya_pasadas_no_se_guardan(): void
    {
        // Cerrar ayer no cambia nada y la lista crecería para siempre.
        Settings::setClosedDays([
            now()->subDay()->format('Y-m-d') => 'ya pasó',
            now()->addDay()->format('Y-m-d') => 'mañana',
        ]);

        $guardados = Settings::closedDays();

        $this->assertCount(1, $guardados);
        $this->assertArrayHasKey(now()->addDay()->format('Y-m-d'), $guardados);
    }

    public function test_una_fecha_con_formato_raro_se_ignora_en_vez_de_romper(): void
    {
        Settings::put('schedule_closed_days', json_encode(['no-es-fecha' => 'x', '2099-01-01' => 'ok']));

        $this->assertSame(['2099-01-01' => 'ok'], Settings::closedDays());
    }

    private function assertRechazado(string $inicio, int $minutos, string $contiene): void
    {
        $motivo = $this->motivo($inicio, $minutos);

        $this->assertNotNull($motivo, "Se esperaba rechazar {$inicio} ({$minutos} min) y se aceptó.");
        $this->assertStringContainsString($contiene, $motivo);
    }

    private function assertAceptado(string $inicio, int $minutos): void
    {
        $this->assertNull(
            $this->motivo($inicio, $minutos),
            "Se esperaba aceptar {$inicio} ({$minutos} min) y se rechazó.",
        );
    }

    /**
     * Llama al guard real. Es privado porque nadie fuera del servicio debe
     * decidir esto, así que el test entra por reflexión en vez de abrirlo.
     */
    private function motivo(string $inicio, int $minutos): ?string
    {
        $tz = Settings::googleTimezone();
        $start = Carbon::parse($inicio, $tz);

        $metodo = new ReflectionMethod(BotService::class, 'motivoFueraDeHorario');
        $metodo->setAccessible(true);

        return $metodo->invoke(
            BotService::fromUser(User::factory()->create()),
            $start,
            $start->copy()->addMinutes($minutos),
        );
    }
}
