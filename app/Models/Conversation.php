<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Conversation extends Model
{
    protected $fillable = ['user_id', 'lead_id', 'campaign_id', 'title', 'channel', 'referral', 'bot_enabled', 'bot_paused_at'];

    protected function casts(): array
    {
        return [
            'referral' => 'array',
            'bot_enabled' => 'boolean',
            'bot_paused_at' => 'datetime',
        ];
    }

    /**
     * Momento del último mensaje entrante de la paciente. Marca el inicio de la
     * ventana de 24 horas de WhatsApp: fuera de ella, Meta solo entrega
     * plantillas aprobadas, no texto libre.
     */
    public function lastInboundAt(): ?Carbon
    {
        $ultimo = $this->messages()->where('role', 'user')->max('created_at');

        return $ultimo ? Carbon::parse($ultimo) : null;
    }

    /** ¿Se le puede escribir texto libre a esta paciente ahora mismo? */
    public function windowIsOpen(): bool
    {
        return (bool) $this->lastInboundAt()?->gt(now()->subDay());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
