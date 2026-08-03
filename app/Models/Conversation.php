<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Conversation extends Model
{
    protected $fillable = ['user_id', 'lead_id', 'campaign_id', 'title', 'channel', 'referral', 'bot_enabled', 'bot_paused_at', 'escalated_at', 'escalation_reason'];

    /**
     * El valor por defecto tiene que estar TAMBIÉN aquí, no solo en la columna.
     *
     * La migración declara `bot_enabled` con `default(true)`, pero ese default
     * lo aplica la BASE al insertar: el modelo recién creado con `create()` o
     * `firstOrCreate()` NO lo conoce y devuelve `null` hasta que se relee. Como
     * `ProcessWhatsAppMessage` comprueba `if (! $conversation->bot_enabled)`
     * justo después de crearla, `! null` daba `true` y el job se salía sin
     * responder — SIN error, porque esa guarda es un `return` mudo.
     *
     * El efecto era que el PRIMER mensaje de cada paciente nueva se descartaba
     * y solo se le contestaba a partir del segundo, que es cuando
     * `firstOrCreate` ya encuentra la fila y la lee de la base con el `true`
     * puesto. Justo el peor mensaje que se puede perder: el primer contacto de
     * alguien que acaba de hacer clic en un anuncio.
     */
    protected $attributes = ['bot_enabled' => true];

    protected function casts(): array
    {
        return [
            'referral' => 'array',
            'bot_enabled' => 'boolean',
            'bot_paused_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    /**
     * ¿El asistente pidió que una persona atendiera este chat y todavía nadie lo hizo?
     *
     * Se apaga sola en cuanto la doctora responde o reactiva a Lore (ver
     * InboxController): la marca es "pendiente de atender", no un histórico.
     */
    public function needsHuman(): bool
    {
        return $this->escalated_at !== null;
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
