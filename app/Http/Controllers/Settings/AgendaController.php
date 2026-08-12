<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los días en que el consultorio no atiende.
 *
 * El horario semanal (lunes a viernes de 8 a 18, domingo cerrado) vive en
 * `Settings::scheduleHours()`. Esto es lo otro: fechas SUELTAS —un festivo,
 * unas vacaciones, un congreso— que no siguen ningún patrón y que hasta ahora
 * solo se podían evitar recordando no aceptar citas ese día.
 *
 * Lo que hace de verdad: Lore deja de ofrecer esos días y se niega a agendar
 * en ellos aunque la paciente los pida por su nombre. La doctora sí puede
 * seguir creando una cita a mano si quiere una excepción — cerrar el día es
 * una instrucción para el bot, no un candado para ella.
 */
class AgendaController extends Controller
{
    public function index(Request $request): Response
    {
        $cerrados = Settings::closedDays();

        // Cada día lleva cuántas citas hay YA agendadas en él. Es el dato que
        // evita el susto: cerrar una fecha no cancela nada, y si ya había
        // pacientes ese día hay que avisarles a mano.
        return Inertia::render('settings/agenda', [
            'dias' => collect($cerrados)
                ->map(fn (string $motivo, string $fecha) => [
                    'fecha' => $fecha,
                    'motivo' => $motivo,
                    // La mayúscula se pone aquí y no con `capitalize` de CSS,
                    // que la pondría en TODAS las palabras: «Lunes 31 De
                    // Agosto De 2026».
                    'etiqueta' => ucfirst(Carbon::parse($fecha)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY')),
                    'citas' => $this->citasEse($fecha),
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha'],
            'motivo' => ['nullable', 'string', 'max:120'],
        ], [
            'fecha.after_or_equal' => 'No tiene sentido cerrar un día que ya pasó.',
            'hasta.after_or_equal' => 'El último día no puede ser anterior al primero.',
        ]);

        $cerrados = Settings::closedDays();

        // Un rango, para no tener que meter las vacaciones día por día.
        $desde = Carbon::parse($datos['fecha']);
        $hasta = filled($datos['hasta'] ?? null) ? Carbon::parse($datos['hasta']) : $desde->copy();

        // Tope de un año: un rango abierto por error cerraría la agenda para
        // siempre y nadie lo notaría hasta que dejaran de entrar citas.
        if ($desde->diffInDays($hasta) > 365) {
            return back()->withErrors(['hasta' => 'El rango no puede pasar de un año.']);
        }

        $nuevos = 0;
        for ($d = $desde->copy(); $d->lte($hasta); $d->addDay()) {
            $clave = $d->format('Y-m-d');
            if (! array_key_exists($clave, $cerrados)) {
                $nuevos++;
            }
            $cerrados[$clave] = (string) ($datos['motivo'] ?? '');
        }

        Settings::setClosedDays($cerrados);

        $citas = $this->citasEntre($desde, $hasta);

        return back()->with('success', $nuevos === 1
            ? 'Ese día queda cerrado. Lore ya no lo ofrece.'
            : "Quedan cerrados {$nuevos} días. Lore ya no los ofrece.")
            ->with('aviso_citas', $citas > 0
                ? "Ojo: ya hay {$citas} cita(s) agendada(s) en esas fechas. Cerrarlas no las cancela; toca avisarles."
                : null);
    }

    public function destroy(Request $request, string $fecha): RedirectResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha), 404);

        $cerrados = Settings::closedDays();
        unset($cerrados[$fecha]);
        Settings::setClosedDays($cerrados);

        return back()->with('success', 'Ese día vuelve a estar disponible.');
    }

    /** Citas ya agendadas en un día concreto. */
    private function citasEse(string $fecha): int
    {
        return Appointment::query()
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereDate('starts_at', $fecha)
            ->count();
    }

    private function citasEntre(Carbon $desde, Carbon $hasta): int
    {
        return Appointment::query()
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereDate('starts_at', '>=', $desde->format('Y-m-d'))
            ->whereDate('starts_at', '<=', $hasta->format('Y-m-d'))
            ->count();
    }
}
