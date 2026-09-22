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
 * El recordatorio (plantilla `recordatorio_confirmar`) pide responder
 * CONFIRMO. Se reconoce por código y sin pasar por Lore, pero solo como
 * respuesta a un recordatorio: fuera de ese contexto «confirmo» puede
 * significar cualquier cosa. Sin botones a propósito: la doctora prefirió que
 * nadie quedara confirmado por un toque sin querer.
 */
class ConfirmacionDeAsistencia
{
    /**
     * Cuánto después del recordatorio se sigue entendiendo un «confirmo»
     * escrito como respuesta a él. Cubre el de 24 h y el de 2 h.
     */
    private const HORAS_VALIDEZ_TEXTO = 48;

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
     * recordatorio reciente. Vale para la plantilla vieja y la nueva: las dos
     * dicen «Te recordamos tu cita».
     */
    public static function citaDelTexto(Conversation $conversacion): ?Appointment
    {
        if (! $conversacion->lead_id || ! self::loUltimoNuestroDice($conversacion, 'Te recordamos tu cita')) {
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
     * La paciente se retracta justo después de confirmar: se equivocó o le
     * surgió algo. Devuelve la cita a la que hay que quitarle la confirmación.
     *
     * Solo cuenta si lo ÚLTIMO que le mandamos fue el «quedó confirmada»: un
     * «no puedo» suelto semanas después es otra conversación, y la reagenda o
     * cancelación ya la maneja Lore.
     */
    public static function citaQueSeRetracta(Conversation $conversacion, string $texto): ?Appointment
    {
        $t = mb_strtolower(trim($texto));
        if (! preg_match('/\b(me equivoqu\w*|error|sin querer|no (puedo|podr[eé]|voy|ir[eé]|asist\w*|alcanzo)|no me queda|reprogram\w*|reagend\w*|cambiar\w*|cancel\w*)\b/u', $t)) {
            return null;
        }

        if (! $conversacion->lead_id || ! self::loUltimoNuestroDice($conversacion, 'Tu asistencia quedó confirmada')) {
            return null;
        }

        return Appointment::query()
            ->where('lead_id', $conversacion->lead_id)
            ->whereNotNull('asistencia_confirmada_at')
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

        self::reflejarEnGoogle($cita);
    }

    public static function quitarConfirmacion(Appointment $cita): void
    {
        $cita->forceFill(['asistencia_confirmada_at' => null])->save();

        self::reflejarEnGoogle($cita);
    }

    /** Lo que se le contesta a la paciente. Fijo: no hay nada que decidir. */
    public static function respuesta(Appointment $cita): string
    {
        $tz = Settings::googleTimezone();
        $cuando = $cita->starts_at->copy()->shiftTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a');
        $nombre = trim(explode(' ', trim((string) ($cita->lead?->name ?: $cita->patient_name)))[0] ?? '');
        $saludo = $nombre !== '' ? '¡Gracias, '.mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8').'!' : '¡Gracias!';

        return "{$saludo} ✅ Tu asistencia quedó confirmada para el {$cuando}. Te esperamos 💙\n\n"
            .'Si te surge algo, escríbenos y te ayudamos a reprogramarla.';
    }

    private static function loUltimoNuestroDice(Conversation $conversacion, string $frase): bool
    {
        $ultimo = $conversacion->messages()
            ->where('role', 'assistant')
            ->latest('id')
            ->first(['content', 'created_at']);

        return $ultimo
            && str_contains((string) $ultimo->content, $frase)
            && $ultimo->created_at->gte(now()->subHours(self::HORAS_VALIDEZ_TEXTO));
    }

    private static function reflejarEnGoogle(Appointment $cita): void
    {
        if (! filled($cita->google_event_id) || ! Settings::hasGoogleCalendar()) {
            return;
        }

        try {
            GoogleCalendarService::fromConfig()->updateEvent($cita);
        } catch (Throwable $e) {
            Log::warning('No se pudo reflejar la confirmación de asistencia en Google Calendar.', [
                'appointment_id' => $cita->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
