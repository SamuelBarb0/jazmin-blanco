<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\GoogleCalendarService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AppointmentController extends Controller
{
    public function index(Request $request): Response
    {
        $appointments = $request->user()->appointments()
            ->with(['service:id,name', 'lead:id,name'])
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('appointments/index', [
            'appointments' => $appointments,
            'services' => $request->user()->services()
                ->orderBy('name')
                ->get(['id', 'name', 'duration_minutes']),
            'leads' => $request->user()->leads()
                ->orderBy('name')
                ->get(['id', 'name', 'phone', 'email']),
            'statuses' => Appointment::STATUSES,
            'googleConfigured' => Settings::hasGoogleCalendar(),
            'serviceAccountEmail' => Settings::googleServiceAccountEmail(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $appointment = $request->user()->appointments()->create($this->toAttributes($request, $data));

        $this->syncToGoogle($appointment);

        return redirect()->route('appointments.index')
            ->with('success', $this->resultMessage($appointment, 'Cita creada.'));
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);

        $data = $this->validateData($request);
        $appointment->update($this->toAttributes($request, $data));

        $this->syncToGoogle($appointment);

        return redirect()->route('appointments.index')
            ->with('success', $this->resultMessage($appointment, 'Cita actualizada.'));
    }

    public function destroy(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);

        // Quita el evento de Google si existe (no bloquea el borrado si falla).
        if (filled($appointment->google_event_id) && Settings::hasGoogleCalendar()) {
            try {
                GoogleCalendarService::fromConfig()->deleteEvent($appointment->google_event_id);
            } catch (Throwable) {
                // ignoramos: la cita se borra del CRM de todas formas
            }
        }

        $appointment->delete();

        return back()->with('success', 'Cita eliminada.');
    }

    /**
     * Crea/actualiza/borra el evento de Google según el estado de la cita.
     * Nunca lanza: registra el resultado en la cita (google_synced_at / google_sync_error).
     */
    private function syncToGoogle(Appointment $appointment): void
    {
        if (! Settings::hasGoogleCalendar()) {
            return;
        }

        $service = GoogleCalendarService::fromConfig();

        try {
            // Las citas canceladas no deben ocupar espacio en el calendario.
            if ($appointment->status === 'cancelled') {
                if (filled($appointment->google_event_id)) {
                    $service->deleteEvent($appointment->google_event_id);
                }
                $appointment->forceFill([
                    'google_event_id' => null,
                    'google_synced_at' => now(),
                    'google_sync_error' => null,
                ])->save();

                return;
            }

            if (filled($appointment->google_event_id)) {
                $service->updateEvent($appointment);
            } else {
                $appointment->google_event_id = $service->createEvent($appointment);
            }

            $appointment->forceFill([
                'google_synced_at' => now(),
                'google_sync_error' => null,
            ])->save();
        } catch (Throwable $e) {
            $appointment->forceFill(['google_sync_error' => $e->getMessage()])->save();
        }
    }

    private function resultMessage(Appointment $appointment, string $base): string
    {
        if (! Settings::hasGoogleCalendar()) {
            return $base.' (Google Calendar no está conectado todavía.)';
        }

        if (filled($appointment->google_sync_error)) {
            return $base.' Pero no se pudo sincronizar con Google: '.$appointment->google_sync_error;
        }

        return $base.' Sincronizada con Google Calendar.';
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function toAttributes(Request $request, array $data): array
    {
        $starts = Carbon::parse($data['starts_at']);

        // Duración: la del servicio si no se especifica; por defecto 45 min.
        $duration = $data['duration_minutes'] ?? null;
        if (! $duration && ! empty($data['service_id'])) {
            $duration = $request->user()->services()->whereKey($data['service_id'])->value('duration_minutes');
        }
        $duration = (int) ($duration ?: 45);

        return [
            'lead_id' => $data['lead_id'] ?? null,
            'service_id' => $data['service_id'] ?? null,
            'patient_name' => $data['patient_name'],
            'patient_phone' => $data['patient_phone'] ?? null,
            'patient_email' => $data['patient_email'] ?? null,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes($duration),
            'status' => $data['status'] ?? 'scheduled',
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'lead_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'patient_name' => ['required', 'string', 'max:255'],
            'patient_phone' => ['nullable', 'string', 'max:50'],
            'patient_email' => ['nullable', 'email', 'max:255'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'status' => ['nullable', 'string', 'in:'.implode(',', Appointment::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Los ids deben pertenecer a la doctora; si no, se ignoran.
        if (! empty($data['lead_id']) && ! $request->user()->leads()->whereKey($data['lead_id'])->exists()) {
            $data['lead_id'] = null;
        }
        if (! empty($data['service_id']) && ! $request->user()->services()->whereKey($data['service_id'])->exists()) {
            $data['service_id'] = null;
        }

        return $data;
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);
    }
}
