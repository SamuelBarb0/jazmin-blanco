<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Le pedí el anticipo por transferencia y espero el comprobante.»
 *
 * No aparta el horario: mientras no llegue el soporte, el hueco le sigue
 * saliendo libre a las demás pacientes. Esa es la diferencia deliberada con
 * `PaymentLink`, que sí reserva porque la pasarela confirma sola.
 */
class TransferRequest extends Model
{
    public const PENDIENTE = 'pending';

    public const CUMPLIDA = 'fulfilled';

    public const VENCIDA = 'expired';

    public const CANCELADA = 'cancelled';

    protected $fillable = [
        'user_id', 'conversation_id', 'lead_id',
        'booking', 'amount', 'status',
        'reminded_at', 'expires_at', 'fulfilled_at', 'appointment_id',
    ];

    protected function casts(): array
    {
        return [
            'booking' => 'array',
            'amount' => 'integer',
            'reminded_at' => 'datetime',
            'expires_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('status', self::PENDIENTE);
    }

    /** El horario que se acordó de palabra, para poder recordárselo tal cual. */
    public function cuando(): ?string
    {
        return $this->booking['fecha_hora'] ?? null;
    }
}
