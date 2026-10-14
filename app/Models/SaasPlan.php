<?php

namespace App\Models;

use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'price_monthly', 'trial_days', 'features', 'is_active'])]
class SaasPlan extends Model
{
    use UsesClubTimezone;

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'trial_days' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ClubSubscription::class);
    }
}
