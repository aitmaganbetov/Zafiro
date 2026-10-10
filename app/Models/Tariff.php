<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description', 'duration_minutes', 'price', 'is_active', 'payment_method', 'currency', 'discount_enabled', 'color', 'club_name', 'usage_limit_enabled', 'usage_limit_minutes', 'quantity_limit_enabled', 'quantity_limit', 'validity_periods'])]
class Tariff extends Model
{
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'discount_enabled' => 'boolean',
            'usage_limit_enabled' => 'boolean',
            'usage_limit_minutes' => 'integer',
            'quantity_limit_enabled' => 'boolean',
            'quantity_limit' => 'integer',
            'validity_periods' => 'array',
        ];
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(ClubZone::class);
    }
}