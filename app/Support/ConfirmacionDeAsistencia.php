<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La paciente confirma que asiste a su cita.
 *
 * Reporte de la doctora del 22/09/2026: el recordatorio terminaba en «Si
 * necesitas reprogramarla, respóndenos», nunca pedía CONFIRMAR, y aunque
 * varias pacientes escribían «Confirmo», nada lo registraba: las 32 citas de
 * la semana seguían igual y ella tenía que confirmar a mano una por una.
 *
 * Dos vías, las dos por código y sin pasar por Lore:
 *  - el botón «Confirmo mi asistencia» de la plantilla `confirmar_cita`, que
 *    trae en el payload el id de LA cita (no hay que adivinar cuál);
 *  - un «confirmo» escrito, pero solo como respuesta a un recordatorio. Fuera
 *    de ese contexto la palabra puede significar cualquier cosa.
 */
class ConfirmacionDeAsistencia
{
    /** Nombre de la plantilla con botones, tal como está en el WhatsApp Manager. */
    public const PLANTILLA = 'confirmar_cita';

    public const PAYLOAD_CONFIRMAR = 'CONFIRMAR_CITA';

    public const PAYLOAD_REPROGRAMAR = 'REPROGRAMAR_CITA';

    /**
     * Cuánto después del recordatorio se sigue entendiendo un «confirmo»
     * escrito como respuesta a él. Cubre el de 24 h y el de 2 h.
     */
    private const HORAS_VALIDEZ_TEXTO = 48;

    public static function payload(string $accion, Appointment $cita): string
    {
        return $accion.':'.$cita->id;
    }

    /**
     * La cita a la que apunta el botón, siempre que sea de quien lo tocó. El
     * payload viaja por Meta; se comprueba el teléfono para que un payload
     * copiado o viejo no confirme la cita de otra persona.
     */
    public static function citaDelBoton(?string $payload, string $telefono): ?Appointment
    {
        if (! $payload || ! preg_match('/^'.self::PAYLOAD_CONFIRMAR.':(\d+)$/', $payload, $m)) {
            return null;
        }

        $cita = Appointment::query()->with('lead:id,phone')->find((int) $m[1]);
        if (! $cita || in_array($cita->status, ['cancelled', 'completed', 'no_show'], true)) {
            return null;
        }

        $mio = self::ultimos10($telefono);
        $suyos = array_filter([self::ultimos10($cita->lead?->phone), self::ultimos10($cita->patient_phone)]);

        return $mio !== '' && in_array($mio, $suyos, true) ? $cita : null;
    }

    public static function esBotonReprogramar(?string $payload): bool
    {
        return $payload !== null && str_starts_with($payload, self::PAYLOAD_REPROGRAMAR.':');
    }

    /**
     * ¿El texto confirma asistencia? «Confirmo», «sí asistiré», «ok confirmo la
     * cita para mañana». No: «no puedo confirmar», «confirmo que necesito
     * cambiarla», «¿puedo reprogramar?».
     */
    public static function esConfirmacionEscrita(string $texto): bool
    {
        $t = mb_strtolower(trim($texto));

        if ($t === '' || mb_strlen($t) > 120) {
            return false;
        }

        // Cualquier señal de cambio o de no poder manda sobre el «confirmo».
        if (preg_match('/\b(no|reprogram\w*|reagend\w*|cambiar\w*|mover\w*|correr\w*|cancel\w*|otro d[ií]a|otra hora)\b/u', $t)) {
            return false;
        }

        return (bool) preg_match('/\b(confirm\w*|s[ií],? asist\w*|asistir[eé]|all[ií] estar[eé]|ah[ií] estar[eé]|ah[ií] nos vemos|cuenten conmigo)\b/u', $t);
    }

    /**
     * La cita que un «confirmo» escrito está confirmando: la próxima de esa
     * paciente, siempre que lo último que le mandamos haya sido un
     * recordatorio reciente.
     */
    public static function citaDelTexto(Conversation $conversacion): ?Appointment
    {
        if (! $conversacion->lead_id) {
            return null;
        }

        $ultimoNuestro = $conversacion->messages()
            ->where('role', 'assistant')
            ->latest('id')
            ->first(['content', 'created_at']);

        if (! $ultimoNuestro
            || ! str_contains((string) $ultimoNuestro->content, 'Te recordamos tu cita')
            || $ultimoNuestro->created_at->lt(now()->subHours(self::HORAS_VALIDEZ_TEXTO))) {
            return null;
        }

        return Appointment::query()
            ->where('lead_id', $conversacion->lead_id)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->where('starts_at', '>=', now()->subHour())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Deja la cita confirmada y lo refleja en Google Calendar, que es donde la
     * doctora mira su agenda. Si Google falla, la confirmación queda igual.
     */
    public static function confirmar(Appointment $cita): void
    {
        if ($cita->asistencia_confirmada_at === null) {
            $cita->forceFill(['asistencia_confirmada_at' => now()])->save();
        }

        if (! filled($cita->google_event_id) || ! Settings::hasGoogleCalendar()) {
            return;
        }

        try {
            GoogleCalendarService::fromConfig()->updateEvent($cita);
        } catch (Throwable $e) {
            Log::warning('La asistencia quedó confirmada, pero no se pudo marcar en Google Calendar.', [
                'appointment_id' => $cita->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Lo que se le contesta a la paciente. Fijo: no hay nada que decidir. */
    public static function respuesta(Appointment $cita): string
    {
        $tz = Settings::googleTimezone();
        $cuando = $cita->starts_at->copy()->shiftTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a');
        $nombre = trim(explode(' ', trim((string) ($cita->lead?->name ?: $cita->patient_name)))[0] ?? '');
        $saludo = $nombre !== '' ? '¡Gracias, '.mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8').'!' : '¡Gracias!';

        return "{$saludo} ✅ Tu asistencia quedó confirmada para el {$cuando}. Te esperamos 💙";
    }

    private static function ultimos10(?string $telefono): string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono);

        return strlen($digitos) >= 10 ? substr($digitos, -10) : '';
    }
}
