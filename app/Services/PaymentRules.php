<?php

namespace App\Services;

/**
 * How customers pay for time, set up by the club (Settings → Finances): where the discount
 * (personal or cumulative) works, and how much of a price bonuses may cover.
 */
class PaymentRules
{
    /** Tariffs marked "Apply discount" (as before), hourly time only (per minute at the zone's price), or both. */
    public const DISCOUNT_SCOPES = ['tariffs', 'hourly', 'all'];

    public function __construct(private CurrentClub $currentClub) {}

    public function discountScope(): string
    {
        $scope = data_get($this->currentClub->settings(), 'finances.discount_scope');

        return in_array($scope, self::DISCOUNT_SCOPES, true) ? $scope : 'tariffs';
    }

    public function discountOnTariffs(): bool
    {
        return $this->discountScope() !== 'hourly';
    }

    public function discountOnHourly(): bool
    {
        return $this->discountScope() !== 'tariffs';
    }

    /** Up to what percent of a price bonuses may pay (0 — bonuses do not pay for time). */
    public function bonusMaxPercent(): int
    {
        $percent = data_get($this->currentClub->settings(), 'finances.bonus_max_percent', 100);

        return is_numeric($percent) ? max(0, min(100, (int) $percent)) : 100;
    }

    /** The part of a price in cents bonuses may pay. */
    public function bonusLimitCents(int $price): int
    {
        return intdiv($price * $this->bonusMaxPercent(), 100);
    }
}
