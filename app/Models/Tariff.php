<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use App\Services\CurrentClub;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description', 'duration_minutes', 'price', 'is_active', 'payment_method', 'currency', 'discount_enabled', 'color', 'club_name', 'usage_limit_enabled', 'usage_limit_minutes', 'quantity_limit_enabled', 'quantity_limit', 'validity_periods', 'sale_starts_at', 'sale_ends_at'])]
class Tariff extends Model
{
    use BelongsToClub, UsesClubTimezone;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'user_roles' => 'array',
            'discount_enabled' => 'boolean',
            'usage_limit_enabled' => 'boolean',
            'usage_limit_minutes' => 'integer',
            'quantity_limit_enabled' => 'boolean',
            'quantity_limit' => 'integer',
            'validity_periods' => 'array',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
        ];
    }

    /** Within its sale time: started (or no start) and not ended (or no end). */
    public function onSale(): bool
    {
        return ($this->sale_starts_at === null || ! $this->sale_starts_at->isFuture())
            && ($this->sale_ends_at === null || $this->sale_ends_at->isFuture());
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(ClubZone::class)
            ->withPivot('club_id')
            ->wherePivot('club_id', app(CurrentClub::class)->id());
    }
}
