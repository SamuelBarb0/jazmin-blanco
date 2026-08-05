<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Services\GoogleCalendarService;
use App\Services\WhatsAppService;
use App\Support\PatientLeads;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

    /**
     * Importa las citas existentes del calendario PRINCIPAL de la doctora a la
     * app, para no perder los pacientes que ya tenía antes de conectar el bot.
     * Requiere OAuth (su token puede leer su calendario principal). Idempotente:
     * no re-crea las ya importadas (dedup por source_event_id). No sincroniza al
     * calendario dedicado — la disponibilidad del bot ya mira el principal.
     */
    public function importFromGoogle(Request $request): RedirectResponse
    {
        if (! Settings::hasGoogleOAuth()) {
            return back()->with('error', 'Para importar tus citas, conecta tu Google con el botón «Conectar con Google» en Configuración → Google Calendar.');
        }

        $tz = Settings::googleTimezone();
        $timeMin = Carbon::now($tz)->startOfDay()->toRfc3339String();
        $timeMax = Carbon::now($tz)->addMonths(12)->toRfc3339String();

        try {
            // Las citas antiguas de la doctora viven en su calendario PRINCIPAL;
            // el dedicado ("Citas Consultorio") lo gestiona la app.
            $events = GoogleCalendarService::fromConfig()->listEvents('primary', $timeMin, $timeMax);
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudieron leer tus citas de Google: '.$e->getMessage());
        }

        $user = $request->user();
        $yaImportados = $user->appointments()->whereNotNull('source_event_id')->pluck('source_event_id')->flip();
        $leads = $user->leads()->get();

        $importadas = 0;
        foreach ($events as $ev) {
            if ($yaImportados->has($ev['id'])) {
                continue;
            }

            $starts = Carbon::parse($ev['starts_at']);
            $ends = Carbon::parse($ev['ends_at']);

            // Cada paciente de la agenda entra también al pipeline. Los títulos
            // que son marcadores del calendario ("FESTIVO", "PERU"…) se importan
            // como cita pero sin crear un lead falso.
            $lead = PatientLeads::resolve($user, $ev['summary'], null, [
                'stage_id' => PatientLeads::stageId($user, $starts->isFuture() ? 'agendado' : 'cerrado'),
                'source' => 'agenda',
                'notes' => 'Paciente importada de tu Google Calendar.',
                'last_contact_at' => $starts->isPast() ? $starts : now(),
            ], $leads);

            if ($lead && ! $leads->contains('id', $lead->id)) {
                $leads->push($lead);
            }

            $user->appointments()->create([
                'lead_id' => $lead?->id,
                'patient_name' => mb_substr($ev['summary'], 0, 255),
                'starts_at' => $starts,
                'ends_at' => $ends->gt($starts) ? $ends : $starts->copy()->addMinutes(45),
                'status' => 'scheduled',
                'notes' => mb_substr(trim("Importada de tu Google Calendar (calendario principal).\n".($ev['description'] ?? '')), 0, 2000),
                'source_event_id' => $ev['id'],
            ]);
            $importadas++;
        }

        return redirect()->route('appointments.index')->with('success', $importadas > 0
            ? "Se importaron {$importadas} citas de tu Google Calendar."
            : 'No hay citas nuevas para importar (ya estaban todas).');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $appointment = $request->user()->appointments()->create($this->toAttributes($request, $data));

        $this->syncToGoogle($appointment);

        // El aviso de «tu cita quedó agendada» es el PRIMERO de la cadena; los
        // recordatorios de 24 h y 2 h vienen después, por el comando programado.
        $aviso = $this->avisarPacienteAgendada($appointment);

        return redirect()->route('appointments.index')
            ->with('success', $this->resultMessage($appointment, 'Cita creada.').$aviso);
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

    /**
     * Avisa a la paciente de que su cita quedó agendada, y devuelve una nota
     * para el mensaje de la pantalla — la doctora tiene que SABER si salió o
     * no, porque hasta ahora agendaba a ciegas y no se enviaba nunca nada.
     *
     * Fuera de la ventana de 24 h WhatsApp solo entrega plantillas, así que
     * ahí se usa la aprobada (`recordatorio_cita`) en vez de texto libre; si se
     * mandara texto, Meta lo rechazaría con `131047` y nadie se enteraría.
     */
    private function avisarPacienteAgendada(Appointment $appointment): string
    {
        $telefono = $appointment->patient_phone ?: $appointment->lead?->phone;

        if (blank($telefono)) {
            return ' ⚠️ Sin teléfono: no se le avisó y tampoco recibirá recordatorios.';
        }

        if (! Settings::autoMessagingAllows($telefono)) {
            return ' (No se le avisó: los mensajes automáticos están en pausa.)';
        }

        $whatsapp = WhatsAppService::fromConfig();
        if (! $whatsapp->isConfigured()) {
            return ' (No se le avisó: WhatsApp no está configurado.)';
        }

        $tz = Settings::googleTimezone();
        $nombre = trim(explode(' ', trim((string) ($appointment->lead?->name ?: $appointment->patient_name)))[0] ?: '');
        $nombre = $nombre !== '' ? mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8') : 'hola';
        $cuando = $appointment->starts_at->copy()->tz($tz)->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a');
        $clinica = Settings::botConfig()['clinic_name'];

        $texto = "¡Hola {$nombre}! 👋 Tu cita quedó agendada para el {$cuando} en {$clinica}. "
            .'Si necesitas reprogramarla, respóndenos por este chat.';

        // La conversación decide si se puede escribir texto libre.
        $conversacion = $appointment->lead
            ? Conversation::where('lead_id', $appointment->lead->id)->where('channel', 'whatsapp')->latest('id')->first()
            : null;

        try {
            if ($conversacion?->windowIsOpen()) {
                $enviado = $whatsapp->sendText($telefono, $texto);
                $via = '';
            } else {
                $idioma = Settings::reminderConfig()['language'];
                $dia = $appointment->starts_at->copy()->tz($tz)->locale('es')->isoFormat('dddd D [de] MMMM');
                $hora = $appointment->starts_at->copy()->tz($tz)->locale('es')->isoFormat('h:mm a');

                // Primero la plantilla propia de confirmación, que dice lo que
                // pidió la doctora: «tu cita ha sido agendada».
                $confirmacion = Settings::confirmationTemplate();
                $enviado = filled($confirmacion)
                    && $whatsapp->sendTemplate($telefono, $confirmacion, $idioma, [$nombre, $dia, $hora, $clinica]);
                $via = ' (por plantilla, porque no ha escrito en 24 h)';

                // Si falla —lo normal mientras Meta la tenga PENDIENTE— se cae a
                // la de recordatorio, que sí está aprobada. Así el día que la
                // aprueben esto empieza a usarla solo, sin tocar nada. Ojo al
                // «el» delante del día: esta plantilla dice «tu cita {{2}}».
                if (! $enviado) {
                    $plantilla = Settings::reminderConfig()['template'];
                    if (blank($plantilla)) {
                        return ' ⚠️ No se le avisó: la paciente no ha escrito en 24 h y no hay plantilla aprobada configurada.';
                    }
                    $enviado = $whatsapp->sendTemplate($telefono, $plantilla, $idioma, [$nombre, "el {$dia}", $hora, $clinica]);
                    $via = ' (por la plantilla de recordatorio; «'.$confirmacion.'» aún no está aprobada en Meta)';
                }
            }
        } catch (Throwable $e) {
            Log::error('No se pudo avisar de la cita agendada', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);

            return ' ⚠️ No se le pudo avisar por WhatsApp.';
        }

        if (! $enviado) {
            return ' ⚠️ No se le pudo avisar por WhatsApp.';
        }

        // Queda en el historial del chat, para que la doctora vea lo que le llegó.
        $conversacion?->messages()->create([
            'role' => 'assistant',
            'sent_by' => 'bot',
            'content' => $texto,
        ]);

        return ' Se le avisó por WhatsApp'.$via.'.';
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

        // Toda cita debe tener a su paciente en el pipeline: si no se eligió un
        // lead, se busca por teléfono/nombre y se crea si es la primera vez.
        $leadId = $data['lead_id'] ?? null;
        if (! $leadId) {
            $leadId = PatientLeads::resolve($request->user(), $data['patient_name'], $data['patient_phone'] ?? null, [
                'stage_id' => PatientLeads::stageId($request->user(), $starts->isFuture() ? 'agendado' : 'cerrado'),
                'email' => $data['patient_email'] ?? null,
                'service_interest' => $request->user()->services()->whereKey($data['service_id'] ?? null)->value('name'),
                'last_contact_at' => now(),
            ])?->id;
        }

        // Si no se escribió teléfono pero el paciente del CRM tiene uno, se
        // hereda. Sin esto la cita nace muda: los recordatorios resuelven el
        // número por `patient_phone` o por el del lead, y al elegir a una
        // paciente del desplegable la doctora da por hecho que ya se sabe su
        // número. De 103 citas solo 22 tenían teléfono propio.
        $telefono = $data['patient_phone'] ?? null;
        if (blank($telefono) && $leadId) {
            $telefono = $request->user()->leads()->whereKey($leadId)->value('phone');
        }

        return [
            'lead_id' => $leadId,
            'service_id' => $data['service_id'] ?? null,
            'patient_name' => $data['patient_name'],
            'patient_phone' => $telefono ?: null,
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

    /**
     * La doctora confirmó en el banco que la transferencia llegó.
     *
     * Quita la marca y vuelve a sincronizar con Google para que el evento
     * pierda el «⚠️ VERIFICAR TRANSFERENCIA» del título: si solo se limpiara en
     * la base, el aviso seguiría en el calendario, que es justo donde ella lo
     * mira, y dejaría de significar nada.
     */
    public function verifyTransfer(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);

        if (! $appointment->transfer_pending_at) {
            return back()->with('success', 'Esa cita no tenía ninguna transferencia pendiente.');
        }

        $appointment->forceFill(['transfer_pending_at' => null])->save();

        $this->syncToGoogle($appointment);

        return back()->with('success', 'Transferencia verificada: la cita de '
            .$appointment->patient_name.' queda confirmada.');
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);
    }
}
