<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use App\Services\CurrentClub;
use App\Support\WeeklyPeriods;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

#[Fillable(['name', 'description', 'duration_minutes', 'price', 'is_active', 'payment_method', 'currency', 'discount_enabled', 'usage_limit_enabled', 'usage_limit_minutes', 'quantity_limit_enabled', 'quantity_limit', 'validity_periods', 'sale_periods'])]
class Tariff extends Model
{
    // Deleted by the owner: off sale everywhere, kept for purchase history and kept passes.
    use BelongsToClub, SoftDeletes, UsesClubTimezone;

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
            'sale_periods' => 'array',
        ];
    }

    /** Now (club time) is within its sale days and times; none set — always on sale. */
    public function onSale(): bool
    {
        return WeeklyPeriods::contains($this->sale_periods, Carbon::now(app(CurrentClub::class)->timezone()));
    }

    /** Now (club time) is within its validity days and times: the tariff time may be used. */
    public function inValidityPeriod(): bool
    {
        return WeeklyPeriods::contains($this->validity_periods, Carbon::now(app(CurrentClub::class)->timezone()));
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(ClubZone::class)
            ->withPivot('club_id')
            ->wherePivot('club_id', app(CurrentClub::class)->id());
    }
}
