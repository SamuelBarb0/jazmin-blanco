<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLink extends Model
{
    /** Estados que devuelve Bold. */
    public const PAGADO = 'PAID';

    protected $fillable = [
        'user_id', 'conversation_id', 'lead_id',
        'reference', 'payment_link', 'url', 'amount', 'description',
        'status', 'payment_method', 'paid_at', 'expires_at', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAGADO;
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
