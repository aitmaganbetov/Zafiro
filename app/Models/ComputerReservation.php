<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A PC booked for a customer for a period. While it is pending (not cancelled, the customer
 * has not arrived yet) nobody else can buy time on the PC that would run into it.
 */
#[Fillable(['club_computer_id', 'user_id', 'customer_name', 'customer_phone', 'note', 'from_app', 'paid_main_cents', 'paid_bonus_cents', 'tariff_id', 'starts_at', 'ends_at', 'arrived_at', 'cancelled_at', 'created_by'])]
class ComputerReservation extends Model
{
    use BelongsToClub, UsesClubTimezone;

    protected function casts(): array
    {
        return [
            'club_computer_id' => 'integer',
            'user_id' => 'integer',
            'from_app' => 'boolean',
            'paid_main_cents' => 'integer',
            'paid_bonus_cents' => 'integer',
            'tariff_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'arrived_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(ClubComputer::class, 'club_computer_id');
    }

    /** The tariff the booking was paid as; null — by the hour. */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Neither cancelled nor closed by the customer's arrival. */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('cancelled_at')->whereNull('arrived_at');
    }

    /** Paid from the guest app. */
    public function scopePaid(Builder $query): void
    {
        $query->where(fn (Builder $paid) => $paid->where('paid_main_cents', '>', 0)->orWhere('paid_bonus_cents', '>', 0));
    }

    public function scopeUnpaid(Builder $query): void
    {
        $query->where('paid_main_cents', 0)->where('paid_bonus_cents', 0);
    }

    /** Pending bookings that overlap [from, to). */
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): void
    {
        // Stored in UTC: the bounds may come in the club's time zone.
        $query->pending()
            ->where('starts_at', '<', $to->copy()->setTimezone(config('app.timezone')))
            ->where('ends_at', '>', $from->copy()->setTimezone(config('app.timezone')));
    }

    /** pending, active, finished, arrived or cancelled. */
    public function state(): string
    {
        return match (true) {
            $this->cancelled_at !== null => 'cancelled',
            $this->arrived_at !== null => 'arrived',
            $this->ends_at->isPast() => 'finished',
            $this->starts_at->isPast() => 'active',
            default => 'pending',
        };
    }

    /** Paid from the guest app: the paid time starts on the PC when the guest comes. */
    public function isPaid(): bool
    {
        return $this->paid_main_cents + $this->paid_bonus_cents > 0;
    }

    public function durationMinutes(): int
    {
        return max(1, (int) round(($this->ends_at->getTimestamp() - $this->starts_at->getTimestamp()) / 60));
    }

    public function customerLabel(): string
    {
        $name = $this->user?->name ?? $this->customer_name;
        $phone = $this->user?->phone ?? $this->customer_phone;

        return trim(implode(' · ', array_filter([$name, $phone])));
    }
}
