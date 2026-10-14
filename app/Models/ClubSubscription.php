<?php

namespace App\Models;

use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['saas_plan_id', 'status', 'started_at', 'trial_ends_at', 'current_period_ends_at', 'canceled_at', 'provider', 'provider_reference'])]
class ClubSubscription extends Model
{
    use UsesClubTimezone;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            || ($this->status === 'trialing' && $this->trial_ends_at?->isFuture() === true);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
