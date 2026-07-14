<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'category',
        'short_description',
        'description',
        'ai_context',
        'price',
        'duration_minutes',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Service $service) {
            if (blank($service->slug) && filled($service->name)) {
                $service->slug = Str::slug($service->name);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Fotos y videos del servicio que el bot puede enviar.
     *
     * @return HasMany<ServiceMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ServiceMedia::class)->orderBy('sort_order')->orderBy('id');
    }
}
