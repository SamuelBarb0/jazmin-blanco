<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Settings;
use Illuminate\Support\Carbon;

class Conversation extends Model
{
    protected $fillable = ['user_id', 'lead_id', 'campaign_id', 'title', 'channel', 'phone_number_id', 'referral', 'bot_enabled', 'bot_paused_at', 'bot_paused_manually', 'escalated_at', 'escalation_reason', 'reactivation_sent_at'];

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
            'bot_paused_manually' => 'boolean',
            'escalated_at' => 'datetime',
            'reactivation_sent_at' => 'datetime',
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
     * ¿Le toca al asistente volver a hacerse cargo de este chat?
     *
     * La pausa que se pone sola al escribirle a mano existe para que Lore no
     * conteste encima de la doctora MIENTRAS están hablando. Pasado ese rato ya
     * no protege nada: solo deja el chat mudo. En producción eso dejó a varias
     * pacientes sin respuesta durante días —una de ellas escribiendo «necesito
     * reprogramarla» la mañana de su cita— sin que fallara nada ni quedara
     * rastro en ningún log, porque la guarda del job es un `return` sin ruido.
     *
     * NO se reanuda en dos casos, y los dos a propósito:
     *  - la pausa del botón, que es una decisión y no un efecto secundario;
     *  - un chat escalado, donde el asistente ya dijo que lo atiende una
     *    persona y volver a hablar sería desdecirse.
     */
    public function debeReanudarAlAsistente(): bool
    {
        if ($this->bot_enabled || $this->bot_paused_manually || $this->needsHuman()) {
            return false;
        }

        $horas = Settings::botResumeHours();

        // Sin horas configuradas la reanudación queda apagada: es el
        // comportamiento anterior, por si alguna vez hay que volver a él.
        if ($horas <= 0) {
            return false;
        }

        // Se mide contra el último mensaje de CUALQUIERA de los dos lados, no
        // solo contra la pausa: si la doctora sigue escribiendo, el chat sigue
        // siendo suyo aunque la pausa sea vieja.
        $ultimo = $this->messages()->max('created_at');
        $referencia = $ultimo ? Carbon::parse($ultimo) : $this->bot_paused_at;

        return $referencia === null || $referencia->lt(now()->subHours($horas));
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
