<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Resuelve el paciente (Lead) detrás de una cita.
 *
 * Las citas pueden llegar de tres lados y no todas traen los mismos datos:
 *  - Google Calendar: solo el título del evento (nombre, a veces con erratas).
 *  - Agenda de la app: nombre + teléfono opcional.
 *  - WhatsApp / bot: teléfono confiable + nombre de perfil.
 *
 * Para que el pipeline no se llene de duplicados, aquí vive la lógica de
 * emparejar por teléfono primero y por nombre después (tolerando erratas), y
 * de descartar los eventos del calendario que no son pacientes.
 */
class PatientLeads
{
    /**
     * Títulos de evento que la doctora usa como marcadores en su calendario
     * personal y que NO son pacientes. Se comparan ya normalizados y solo por
     * coincidencia EXACTA, para no descartar a un paciente que se llame, por
     * ejemplo, "Domingo Pérez".
     *
     * @var list<string>
     */
    public const NON_PATIENT = [
        'peru', 'proyecto ia',
        'festivo', 'estivo', 'feriado', 'no laboral', 'cerrado', 'descanso',
        'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo',
        'vacaciones', 'viaje', 'almuerzo', 'reunion', 'personal', 'cumpleanos',
        'bloqueado', 'ocupado', 'libre', 'curso', 'congreso',
    ];

    /** Nombre en minúsculas, sin tildes y con espacios colapsados. */
    public static function normalize(?string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii((string) $name))));
    }

    /** Solo los dígitos de un teléfono (para comparar +57 300… con 300…). */
    public static function digits(?string $phone): string
    {
        return preg_replace('/\D/', '', (string) $phone) ?: '';
    }

    /** ¿El título del evento es un marcador del calendario y no una persona? */
    public static function isNonPatient(?string $name): bool
    {
        $n = self::normalize($name);

        // Vacíos, demasiado cortos o sin una sola letra: no son un nombre.
        if ($n === '' || mb_strlen($n) < 3 || ! preg_match('/[a-z]/', $n)) {
            return true;
        }

        return in_array($n, self::NON_PATIENT, true);
    }

    /** Nombre presentable: la agenda mezcla MAYÚSCULAS y minúsculas. */
    public static function pretty(?string $name): string
    {
        return mb_convert_case(trim(preg_replace('/\s+/', ' ', (string) $name)), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * ¿Los dos nombres son de la misma persona? Tolera las erratas típicas de
     * escribir la agenda a mano ("NBIA" por "NUBIA", "MARJKO" por "MARKO") y
     * los apellidos que a veces se anotan y a veces no.
     */
    public static function sameName(?string $a, ?string $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        // Errata de una o dos letras (solo en nombres largos, donde el riesgo
        // de confundir a dos personas distintas es bajo).
        if (min(mb_strlen($a), mb_strlen($b)) >= 8 && levenshtein($a, $b) <= 2) {
            return true;
        }

        // Un nombre está contenido en el otro, palabra por palabra y seguidas:
        // "Nubia Andrea Pinzón" ⊂ "Nubia Andrea Pinzón González" (falta apellido)
        // "Gabriel Umbarilla"   ⊂ "Luis Gabriel Umbarilla"       (falta primer nombre)
        // Se exigen 2 palabras para no fusionar a todas las "María".
        $wa = explode(' ', $a);
        $wb = explode(' ', $b);
        [$short, $long] = count($wa) <= count($wb) ? [$wa, $wb] : [$wb, $wa];

        if (count($short) < 2) {
            return false;
        }

        for ($i = 0; $i + count($short) <= count($long); $i++) {
            if (array_slice($long, $i, count($short)) === $short) {
                return true;
            }
        }

        return false;
    }

    /**
     * Busca el paciente entre los leads de la doctora: primero por teléfono
     * (dato duro) y si no, por nombre.
     *
     * @param  \Illuminate\Support\Collection<int,Lead>|null  $pool  leads ya cargados, para no consultar en cada cita
     */
    public static function find(User $user, ?string $name, ?string $phone = null, $pool = null): ?Lead
    {
        $pool ??= $user->leads()->get();

        $digits = self::digits($phone);
        if (strlen($digits) >= 7) {
            $byPhone = $pool->first(function (Lead $l) use ($digits) {
                $d = self::digits($l->phone);

                return $d !== '' && (str_ends_with($d, $digits) || str_ends_with($digits, $d));
            });

            if ($byPhone) {
                return $byPhone;
            }
        }

        if (blank($name)) {
            return null;
        }

        // Con nombre, prefiere al lead que todavía no tiene teléfono: suele ser
        // el importado del calendario, que es justo el que queremos completar.
        $porNombre = $pool->filter(fn (Lead $l) => self::sameName($l->name, $name));

        return $porNombre->firstWhere(fn (Lead $l) => blank($l->phone)) ?? $porNombre->first();
    }

    /**
     * Devuelve el paciente, creándolo si es la primera vez que aparece.
     * Si ya existía sin teléfono y ahora lo tenemos, se lo completa (así el
     * paciente del calendario y el que escribe por WhatsApp no se duplican).
     *
     * @param  array<string,mixed>  $attrs  valores para cuando haya que crearlo
     * @param  \Illuminate\Support\Collection<int,Lead>|null  $pool
     */
    public static function resolve(User $user, ?string $name, ?string $phone = null, array $attrs = [], $pool = null): ?Lead
    {
        if (self::isNonPatient($name) && blank($phone)) {
            return null;
        }

        $lead = self::find($user, $name, $phone, $pool);

        if ($lead) {
            $cambios = [];
            if (blank($lead->phone) && filled($phone)) {
                $cambios['phone'] = $phone;
            }
            // Completa el nombre si el que tenemos ahora es más específico.
            if (filled($name) && mb_strlen(self::normalize($name)) > mb_strlen(self::normalize($lead->name))) {
                $cambios['name'] = self::pretty($name);
            }
            if ($cambios) {
                $lead->forceFill($cambios)->save();
            }

            return $lead;
        }

        $stageId = $attrs['stage_id'] ?? self::stageId($user, 'nuevo');

        return $user->leads()->create([
            'stage_id' => $stageId,
            'name' => self::pretty($name) ?: $phone,
            'phone' => $phone,
            'channel' => 'manual',
            'source' => 'agenda',
            'position' => self::nextPosition($user, $stageId),
            'last_contact_at' => now(),
            ...$attrs,
        ]);
    }

    /** Id de una etapa por slug ('agendado', 'cerrado'…), o la primera que haya. */
    public static function stageId(User $user, string $slug): ?int
    {
        return $user->stages()->where('slug', $slug)->value('id')
            ?? $user->stages()->orderBy('position')->value('id');
    }

    /** Siguiente hueco al final de la columna del Kanban. */
    public static function nextPosition(User $user, ?int $stageId): int
    {
        return (int) $user->leads()->where('stage_id', $stageId)->max('position') + 1;
    }
}
