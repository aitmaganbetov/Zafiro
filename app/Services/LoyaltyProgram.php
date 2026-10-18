<?php

namespace App\Services;

use App\Models\ClubMembership;

/**
 * Cumulative discount set up by the club (Settings → Finances): a customer who has spent at least
 * "spent" in the club gets "percent" off. Spending is what the customer paid from the main balance
 * for tariffs, time and agent shop orders (bonuses are not counted); it is kept in
 * club_memberships.total_spent. The discount works where the personal one does — tariffs with
 * "Apply discount" — and the larger of the two applies.
 */
class LoyaltyProgram
{
    public const MAX_LEVELS = 20;

    public function __construct(private CurrentClub $currentClub) {}

    public function enabled(): bool
    {
        return (bool) data_get($this->currentClub->settings(), 'finances.loyalty_enabled', false);
    }

    /** @return list<array{spent: int|float, percent: int|float}> the levels in force, by spent ascending */
    public function levels(): array
    {
        return $this->enabled() ? self::normalize(data_get($this->currentClub->settings(), 'finances.loyalty_levels', [])) : [];
    }

    /** The percent of the highest level the customer's spending has reached; 0 — none. */
    public function percentFor(ClubMembership $membership): float
    {
        $spent = AgentTariffPurchaseService::amountToCents((string) ($membership->total_spent ?? '0'));
        $percent = 0.0;
        foreach ($this->levels() as $level) {
            if ($spent >= (int) round($level['spent'] * 100)) {
                $percent = max($percent, (float) $level['percent']);
            }
        }

        return $percent;
    }

    /** The customer's discount: the personal one or the cumulative one, whichever is larger. */
    public function discountFor(ClubMembership $membership): float
    {
        return max((float) $membership->discount, $this->percentFor($membership));
    }

    /** Money the customer paid from the main balance, in cents. */
    public function addSpending(int $userId, int $cents): void
    {
        if ($cents > 0) {
            ClubMembership::query()->where('user_id', $userId)->increment('total_spent', AgentTariffPurchaseService::formatCents($cents));
        }
    }

    /** A refund (cancelled order): spending goes down, not below zero. */
    public function subtractSpending(int $userId, int $cents): void
    {
        if ($cents <= 0) {
            return;
        }
        $amount = AgentTariffPurchaseService::formatCents($cents);
        $memberships = ClubMembership::query()->where('user_id', $userId);
        if ((clone $memberships)->where('total_spent', '>=', $amount)->decrement('total_spent', $amount) === 0) {
            $memberships->update(['total_spent' => 0]);
        }
    }

    /**
     * Levels as stored: rows with both fields empty dropped, numbers rounded to the cent,
     * sorted by spent.
     *
     * @return list<array{spent: int|float, percent: int|float}>
     */
    public static function normalize(mixed $levels): array
    {
        if (! is_array($levels)) {
            return [];
        }

        $number = fn (mixed $value): int|float => floor($rounded = round((float) $value, 2)) === $rounded ? (int) $rounded : $rounded;

        return collect($levels)
            ->filter(fn (mixed $level): bool => is_array($level) && is_numeric($level['spent'] ?? null) && is_numeric($level['percent'] ?? null))
            ->map(fn (array $level): array => ['spent' => $number($level['spent']), 'percent' => $number($level['percent'])])
            ->sortBy('spent')
            ->values()
            ->all();
    }
}
