<?php

namespace App\Models;

use App\Support\Settings;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    public const STATUSES = ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'];

    protected $fillable = [
        'user_id',
        'lead_id',
        'service_id',
        'patient_name',
        'patient_phone',
        'patient_email',
        'starts_at',
        'ends_at',
        'status',
        'notes',
        'google_event_id',
        'google_synced_at',
        'google_sync_error',
        'source_event_id',
        'reminder_2h_sent_at',
        'reminder_24h_sent_at',
        'transfer_pending_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'google_synced_at' => 'datetime',
            'reminder_2h_sent_at' => 'datetime',
            'reminder_24h_sent_at' => 'datetime',
            'transfer_pending_at' => 'datetime',
        ];
    }

    /**
     * Serializa las fechas como hora local "de pared" (sin offset), para que
     * el front y Google Calendar las traten en la zona horaria del consultorio.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s');
    }

    /**
     * El teléfono al que se le puede escribir por WhatsApp, ya con indicativo,
     * o null si no hay uno utilizable.
     *
     * Meta EXIGE el indicativo de país. Un móvil colombiano de 10 dígitos
     * enviado tal cual se acepta con un 200 y rebota después con
     * `131026 Message undeliverable`: el fallo no se ve al enviar, solo
     * aparece más tarde en `delivery_failures`. En producción, 67 de los 82
     * teléfonos están guardados sin el 57.
     *
     * Vive aquí, en el modelo, y no en cada quien que lo necesite, porque ya
     * se pagó el precio de tenerlo duplicado: el comando de recordatorios
     * normalizaba y el aviso al agendar no, así que a la misma paciente le
     * llegaba el recordatorio pero nunca la confirmación.
     */
    public function telefonoWhatsapp(?string $codigoPais = null): ?string
    {
        // La regla en sí vive en Settings desde que el mensaje de reactivación
        // necesitó exactamente la misma sobre el teléfono del LEAD: tenerla dos
        // veces es la forma conocida de que una de las dos se quede sin arreglar.
        return Settings::phoneWithCountryCode($this->patient_phone ?: $this->lead?->phone, $codigoPais);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
