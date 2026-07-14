<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ServiceMedia extends Model
{
    protected $table = 'service_media';

    protected $fillable = [
        'service_id',
        'user_id',
        'type',
        'path',
        'url',
        'caption',
        'sort_order',
    ];

    protected $appends = ['resolved_url'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * URL pública del medio: el archivo subido (disco public) o la URL externa.
     */
    public function getResolvedUrlAttribute(): ?string
    {
        if (filled($this->path)) {
            return Storage::disk('public')->url($this->path);
        }

        return $this->url;
    }
}
