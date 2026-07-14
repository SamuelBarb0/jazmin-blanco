<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'google_synced_at' => 'datetime',
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
