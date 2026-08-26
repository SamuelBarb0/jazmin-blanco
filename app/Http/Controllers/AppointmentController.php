<?php

namespace App\Http\Controllers;

use App\Mail\CitaParaLaPaciente;
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
use Illuminate\Support\Facades\Mail;
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
        $aviso = $this->avisarPaciente($appointment)
            .$this->avisarPacientePorCorreo($appointment);

        return redirect()->route('appointments.index')
            ->with('success', $this->resultMessage($appointment, 'Cita creada.').$aviso);
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);

        $data = $this->validateData($request);
        $antes = $appointment->starts_at?->copy();

        $appointment->update($this->toAttributes($request, $data));

        $this->syncToGoogle($appointment);

        $aviso = '';

        if ($antes && $appointment->starts_at->ne($antes)) {
            // Las marcas de recordatorio se refieren a la fecha VIEJA y nada
            // las volvía a poner en null: a quien ya había recibido el aviso de
            // 24 h no le llegaba NINGUNO para la fecha nueva, y se quedaba con
            // el recordatorio de la fecha anterior como última información.
            // Se limpian aparte del aviso, y antes, porque esto hay que
            // arreglarlo aunque el WhatsApp falle.
            $appointment->forceFill([
                'reminder_24h_sent_at' => null,
                'reminder_2h_sent_at' => null,
            ])->save();

            $aviso = $this->avisarPaciente($appointment, 'reprogramada')
                .$this->avisarPacientePorCorreo($appointment, 'reprogramada');
        }

        return redirect()->route('appointments.index')
            ->with('success', $this->resultMessage($appointment, 'Cita actualizada.').$aviso);
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
     * Avisa a la paciente de que su cita quedó agendada —o de que le cambió la
     * fecha—, y devuelve una nota para el mensaje de la pantalla: la doctora
     * tiene que SABER si salió o no, porque hasta ahora agendaba a ciegas y no
     * se enviaba nunca nada.
     *
     * Fuera de la ventana de 24 h WhatsApp solo entrega plantillas, así que ahí
     * se usan las aprobadas en vez de texto libre; si se mandara texto, Meta lo
     * rechazaría con `131047` y nadie se enteraría.
     *
     * @param  string  $tipo  'agendada' (cita nueva), 'reprogramada' (cambió la
     *                        hora) o 'confirmada' (le verificamos el pago)
     */
    private function avisarPaciente(Appointment $appointment, string $tipo = 'agendada'): string
    {
        $reprogramada = $tipo === 'reprogramada';
        $confirmada = $tipo === 'confirmada';

        // Con indicativo. Antes se mandaba el número crudo y, como 67 de los
        // 82 teléfonos están guardados a 10 dígitos, Meta aceptaba el envío
        // con 200 y lo rebotaba después con `131026`: la confirmación no
        // llegaba y no había forma de notarlo desde aquí. Los recordatorios sí
        // llegaban, porque su comando sí normalizaba.
        $telefono = $appointment->telefonoWhatsapp();

        if (blank($telefono)) {
            if ($reprogramada) {
                return ' ⚠️ Sin teléfono: no se le pudo avisar del cambio de fecha.';
            }

            return $confirmada
                ? ' ⚠️ Sin teléfono: no se le pudo confirmar el pago por WhatsApp.'
                : ' ⚠️ Sin teléfono: no se le avisó y tampoco recibirá recordatorios.';
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
        // shiftTimezone y NO tz(): `starts_at` guarda la hora de PARED del
        // consultorio (lo dice `Appointment::serializeDate()`), pero
        // `app.timezone` es UTC, así que Laravel la lee etiquetada como UTC.
        // `tz()` convierte el instante y le restaba 5 horas —una cita de las
        // 2:00 p. m. se le anunciaba a la paciente a las 9:00 a. m.—;
        // `shiftTimezone()` solo cambia la etiqueta y conserva el reloj, que es
        // lo que aquí hace falta. Los recordatorios ya lo hacían bien
        // (SendAppointmentReminders:283) y este camino se había quedado atrás.
        $cuando = $appointment->starts_at->copy()->shiftTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a');
        $clinica = Settings::botConfig()['clinic_name'];

        $texto = match (true) {
            $reprogramada => "¡Hola {$nombre}! 👋 Tu cita fue reprogramada: queda para el {$cuando} en {$clinica}. "
                .'Si ese horario no te sirve, respóndenos por este chat.',
            // Es la respuesta a un «apenas se refleje te aviso» que el
            // asistente ya le prometió, así que lo primero que tiene que decir
            // es que el pago llegó: eso es lo que la paciente está esperando.
            $confirmada => "¡Hola {$nombre}! 👋 Confirmamos tu pago ✅ Tu cita queda confirmada para el {$cuando} en {$clinica}. "
                .'Si necesitas reprogramarla, respóndenos por este chat.',
            default => "¡Hola {$nombre}! 👋 Tu cita quedó agendada para el {$cuando} en {$clinica}. "
                .'Si necesitas reprogramarla, respóndenos por este chat.',
        };

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
                $dia = $appointment->starts_at->copy()->shiftTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM');
                $hora = $appointment->starts_at->copy()->shiftTimezone($tz)->locale('es')->isoFormat('h:mm a');

                // Se prueban POR ORDEN: primero la que dice exactamente lo que
                // pasó y, si Meta la rechaza —lo normal mientras esté
                // PENDIENTE—, se cae a la siguiente, que ya está aprobada. Así
                // el día que aprueben la nueva empieza a usarse sola, sin
                // desplegar ni tocar ajustes.
                //
                // Ojo al «el» delante del día: las de agendada/reprogramada
                // dicen «para el {{2}} a las {{3}}» y la de recordatorio «tu
                // cita {{2}} ({{3}})», así que el parámetro NO es
                // intercambiable palabra por palabra.
                $candidatas = [];

                if ($reprogramada) {
                    $candidatas[] = [Settings::rescheduleTemplate(), [$nombre, $dia, $hora, $clinica]];
                }

                $candidatas[] = [Settings::confirmationTemplate(), [$nombre, $dia, $hora, $clinica]];
                $candidatas[] = [Settings::reminderConfig()['template'], [$nombre, "el {$dia}", $hora, $clinica]];

                $enviado = false;
                $via = '';
                $intentadas = [];

                foreach ($candidatas as [$plantilla, $parametros]) {
                    if (blank($plantilla)) {
                        continue;
                    }

                    $intentadas[] = $plantilla;

                    if ($whatsapp->sendTemplate($telefono, $plantilla, $idioma, $parametros)) {
                        $enviado = true;
                        $via = count($intentadas) === 1
                            ? ' (por plantilla, porque no ha escrito en 24 h)'
                            : ' (por la plantilla «'.$plantilla.'»; «'.$intentadas[0].'» aún no está aprobada en Meta)';

                        break;
                    }
                }

                if ($intentadas === []) {
                    return ' ⚠️ No se le avisó: la paciente no ha escrito en 24 h y no hay plantilla aprobada configurada.';
                }
            }
        } catch (Throwable $e) {
            Log::error('No se pudo avisar a la paciente de su cita', [
                'appointment_id' => $appointment->id,
                'tipo' => $tipo,
                'error' => $e->getMessage(),
            ]);

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

        $hecho = match (true) {
            $reprogramada => ' Se le avisó del cambio por WhatsApp',
            $confirmada => ' Se le confirmó el pago y la cita por WhatsApp',
            default => ' Se le avisó por WhatsApp',
        };

        return $hecho.$via.'.';
    }

    /**
     * El mismo aviso, por correo.
     *
     * Va APARTE del de WhatsApp y no dentro de él porque los dos canales fallan
     * por motivos distintos y no se sustituyen: WhatsApp necesita teléfono
     * registrado y, fuera de la ventana de 24 h, una plantilla aprobada por
     * Meta; el correo solo necesita dirección. De 102 citas solo 31 tienen
     * teléfono, así que para buena parte de las pacientes este correo es el
     * único aviso que van a recibir.
     *
     * Devuelve un trozo de frase para el mensaje de «Cita creada», igual que
     * `avisarPaciente()`: la doctora tiene que saber, en el momento de guardar,
     * qué salió y qué no. Nunca lanza: que el correo falle no puede tumbar el
     * guardado de una cita que ya está creada y sincronizada con Google.
     *
     * @param  string  $tipo  'agendada' (cita nueva) o 'reprogramada' (cambió la hora)
     */
    private function avisarPacientePorCorreo(Appointment $appointment, string $tipo = 'agendada'): string
    {
        $correo = $appointment->patient_email ?: $appointment->lead?->email;

        if (blank($correo)) {
            return '';   // Sin correo no hay nada que avisar ni de qué informar.
        }

        // El mismo freno que el de WhatsApp: si los mensajes automáticos están
        // en pausa, es para todos los canales. Se comprueba con el teléfono
        // porque la lista blanca está hecha de números; sin teléfono se mira
        // solo el interruptor general.
        if (! Settings::autoMessagingAllows($appointment->patient_phone ?: $appointment->lead?->phone)) {
            return '';
        }

        try {
            Mail::to($correo)->send(new CitaParaLaPaciente($appointment, $tipo));
        } catch (Throwable $e) {
            Log::error('No se pudo enviar el correo de la cita', [
                'appointment_id' => $appointment->id,
                'tipo' => $tipo,
                'error' => $e->getMessage(),
            ]);

            return ' ⚠️ No se le pudo enviar el correo.';
        }

        return ' Se le envió el correo a '.$correo.'.';
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

        // Duración: la escrita a mano manda, luego la del servicio y por último
        // la de la clínica (`Settings`), la misma que usa el bot. Aquí casi
        // siempre viene escrita: el formulario del panel la prellena.
        $duration = $data['duration_minutes'] ?? null;
        if (! $duration && ! empty($data['service_id'])) {
            $duration = $request->user()->services()->whereKey($data['service_id'])->value('duration_minutes');
        }
        $duration = (int) ($duration ?: Settings::defaultAppointmentMinutes());

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

        $appointment->forceFill([
            'transfer_pending_at' => null,
            // La cita deja de estar «apartada a la espera del pago» y pasa a
            // estar confirmada de verdad. `confirmed` cuenta igual que
            // `scheduled` en agenda, recordatorios y disponibilidad, así que
            // esto solo cambia lo que se lee en pantalla.
            'status' => $appointment->status === 'scheduled' ? 'confirmed' : $appointment->status,
        ])->save();

        $this->syncToGoogle($appointment);

        // ESTO ES LO QUE FALTABA. El asistente le promete a la paciente «apenas
        // se refleje el pago te confirmo la cita», y al verificar la
        // transferencia aquí no salía absolutamente nada: la doctora tenía que
        // escribirle a mano, sin saber siquiera que el sistema no lo había
        // hecho. Se avisa por los dos canales, igual que al agendar.
        $aviso = $this->avisarPaciente($appointment, 'confirmada')
            .$this->avisarPacientePorCorreo($appointment, 'confirmada');

        return back()->with('success', 'Transferencia verificada: la cita de '
            .$appointment->patient_name.' queda confirmada.'.$aviso);
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        abort_unless($request->user()->esDeSuCuenta($appointment), 403);
    }
}
