<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Lead extends Model
{
    protected $fillable = [
        'user_id',
        'stage_id',
        'campaign_id',
        'name',
        'phone',
        'email',
        'channel',
        'source',
        'service_interest',
        'notes',
        'value',
        'position',
        'last_contact_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'position' => 'integer',
            'last_contact_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
