<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLink extends Model
{
    /**
     * Vocabulario interno de estados, al que se traducen los de la pasarela:
     * ACTIVE | PENDING | PROCESSING | PAID | REJECTED | CANCELLED | EXPIRED | REFUNDED
     */
    public const PAGADO = 'PAID';

    protected $fillable = [
        'user_id', 'conversation_id', 'lead_id',
        'reference', 'payment_link', 'url', 'amount', 'description',
        'booking', 'appointment_id',
        'status', 'payment_method', 'paid_at', 'expires_at', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'booking' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAGADO;
    }

    /**
     * ¿Se puede agendar sola esta cita en cuanto entre el pago?
     *
     * Hace falta el horario acordado: sin él el barrido sabe que pagó, pero no
     * para cuándo, y hay que esperar a que la paciente escriba.
     */
    public function canAutoBook(): bool
    {
        return $this->appointment_id === null
            && filled($this->booking['fecha_hora'] ?? null)
            && filled($this->booking['nombre_paciente'] ?? null);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
