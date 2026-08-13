<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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

    /**
     * Links que están RESERVANDO un horario: se generaron con día y hora, aún
     * no se convirtieron en cita y todavía se pueden pagar.
     */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereIn('status', ['ACTIVE', 'PENDING', 'PROCESSING'])
            ->whereNull('appointment_id')
            ->whereNotNull('booking')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Horarios apartados por pagos en curso, en el mismo formato que devuelve
     * `GoogleCalendarService::busyTimes()`.
     *
     * Existe porque el hueco NO se bloquea en Google mientras la paciente paga:
     * sin esto, Lore le puede ofrecer la misma hora a dos personas, cobrarles a
     * las dos y dejar a la segunda con un pago sin cita. La reserva vive aquí y
     * no en el calendario a propósito — así la doctora no ve eventos fantasma
     * de citas que quizá nunca se paguen, y al vencer el link se libera sola.
     *
     * `$exceptConversationId` deja fuera las reservas de la propia paciente:
     * su hora sigue estando disponible PARA ELLA.
     *
     * @return array<int,array{start:Carbon,end:Carbon}>
     */
    public static function heldSlots(int $userId, string $tz, ?int $exceptConversationId = null): array
    {
        return static::query()
            ->where('user_id', $userId)
            ->holding()
            ->when($exceptConversationId, fn (Builder $q) => $q->where('conversation_id', '!=', $exceptConversationId))
            ->get()
            ->map(function (self $link) use ($tz) {
                $cuando = $link->booking['fecha_hora'] ?? null;

                if (blank($cuando)) {
                    return null;
                }

                $inicio = Carbon::parse($cuando, $tz);
                $minutos = (int) ($link->booking['duracion_minutos'] ?? 0) ?: Settings::defaultAppointmentMinutes();

                return ['start' => $inicio, 'end' => $inicio->copy()->addMinutes($minutos)];
            })
            ->filter()
            ->values()
            ->all();
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
