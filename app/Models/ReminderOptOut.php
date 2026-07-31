<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paciente que pidió no recibir recordatorios de cita.
 *
 * La clave es el teléfono, no el lead: el recordatorio se manda al número que
 * salga de la cita, que no siempre tiene un lead vinculado.
 */
class ReminderOptOut extends Model
{
    protected $fillable = ['user_id', 'lead_id', 'phone', 'source'];

    /**
     * Últimos 10 dígitos del número. Es la misma regla que usa la lista blanca
     * de pruebas: así da igual si viene como "+57 312 365 2269", "573123652269"
     * o "3123652269".
     */
    public static function normalize(string $phone): string
    {
        return substr(Settings::normalizePhone($phone), -10);
    }

    /** ¿Este número pidió que no le escribiéramos? */
    public static function has(int $userId, string $phone): bool
    {
        $key = self::normalize($phone);

        return strlen($key) === 10
            && self::where('user_id', $userId)->where('phone', $key)->exists();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
