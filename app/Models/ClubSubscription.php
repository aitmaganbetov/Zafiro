<?php

namespace App\Models;

use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Paid ("active") until current_period_ends_at; without that date it does not end
     * (subscriptions set up by hand). A trial lasts until trial_ends_at.
     */
    public function isActive(): bool
    {
        return ($this->status === 'active' && ($this->current_period_ends_at === null || $this->current_period_ends_at->isFuture()))
            || ($this->status === 'trialing' && $this->trial_ends_at?->isFuture() === true);
    }

    /** The paid period is over but the status is not updated yet (subscriptions:expire does it). */
    public function isPastPaidPeriod(): bool
    {
        return in_array($this->status, ['active', 'expired'], true) && $this->current_period_ends_at?->isPast() === true;
    }

    /** Same rule as isActive(), in SQL. */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where(fn (Builder $active) => $active
            ->where(fn (Builder $paid) => $paid->where('status', 'active')
                ->where(fn (Builder $period) => $period->whereNull('current_period_ends_at')->orWhere('current_period_ends_at', '>', now())))
            ->orWhere(fn (Builder $trial) => $trial->where('status', 'trialing')->where('trial_ends_at', '>', now())));
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
