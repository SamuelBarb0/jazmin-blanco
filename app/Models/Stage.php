<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Stage extends Model
{
    protected $fillable = ['user_id', 'name', 'slug', 'color', 'position', 'is_won', 'is_lost'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Stage $stage) {
            if (blank($stage->slug) && filled($stage->name)) {
                $stage->slug = Str::slug($stage->name);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
