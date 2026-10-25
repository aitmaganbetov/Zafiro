<?php

namespace App\Services;

/**
 * How customers pay for time, set up by the club (Settings → Finances): where the discount
 * (personal or cumulative) works, how much of a price bonuses may cover and whether one
 * purchase may be paid from both balances at once (mixed payment).
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

    /**
     * Per-minute billing starts by itself: after the customer signs in (once the pause is over) and
     * when a tariff runs out while they are still signed in. The agent buys the minutes the main
     * balance pays for; unused ones come back when the session ends.
     */
    public function autoBillingEnabled(): bool
    {
        return (bool) data_get($this->currentClub->settings(), 'finances.auto_billing', false);
    }

    /** Minutes after sign-in before per-minute billing starts: time to pick a tariff. */
    public function autoBillingPauseMinutes(): int
    {
        $minutes = data_get($this->currentClub->settings(), 'finances.auto_billing_pause_minutes', 5);

        return is_numeric($minutes) ? max(0, min(60, (int) $minutes)) : 5;
    }

    /** A customer may buy as many minutes as the main balance pays for, without picking a tariff. */
    public function wholeBalanceAllowed(): bool
    {
        return (bool) data_get($this->currentClub->settings(), 'finances.allow_whole_balance', false);
    }

    /** Bonuses for a part of the price and the rest from the main balance; off — one balance per purchase. */
    public function mixedPaymentAllowed(): bool
    {
        return (bool) data_get($this->currentClub->settings(), 'finances.allow_mixed_payment', false);
    }

    /**
     * The percent the agent offers to pay with bonuses: 0 — the agent does not ask (the main balance).
     * Without mixed payment bonuses must pay the whole price, so a limit under 100% offers nothing.
     */
    public function bonusPercentOffered(bool $mainBalanceOnly = false): int
    {
        $percent = $mainBalanceOnly ? 0 : $this->bonusMaxPercent();

        return $percent < 100 && ! $this->mixedPaymentAllowed() ? 0 : $percent;
    }

    /** The part of a price in cents bonuses may pay. */
    public function bonusLimitCents(int $price): int
    {
        return intdiv($price * $this->bonusMaxPercent(), 100);
    }
}
