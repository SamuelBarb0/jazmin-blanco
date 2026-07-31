<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un mensaje que WhatsApp NO entregó, tal como lo reportó Meta.
 */
class DeliveryFailure extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['phone', 'code', 'title', 'details', 'wamid', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
