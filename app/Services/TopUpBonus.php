<?php

namespace App\Services;

use App\Support\PercentLevels;

/**
 * Bonus for a top-up, set up by the club (Settings → Finances): a top-up of the main balance of
 * at least "amount" adds "percent" of it to the bonus balance. Switched off — no bonus.
 */
class TopUpBonus
{
    public const MAX_LEVELS = 20;

    public function __construct(private CurrentClub $currentClub) {}

    public function enabled(): bool
    {
        return (bool) data_get($this->currentClub->settings(), 'finances.topup_bonus_enabled', false);
    }

    /** @return list<array{amount: int|float, percent: int|float}> the levels in force, by amount ascending */
    public function levels(): array
    {
        return $this->enabled() ? self::normalize(data_get($this->currentClub->settings(), 'finances.topup_bonus_levels', [])) : [];
    }

    /** @return array{percent: float, cents: int} the bonus for a top-up of the main balance (rounded half up to the cent) */
    public function for(float $amount): array
    {
        $cents = (int) round($amount * 100);
        $percent = PercentLevels::percentAt($this->levels(), 'amount', $cents);

        return ['percent' => $percent, 'cents' => intdiv($cents * (int) round($percent * 100) + 5000, 10000)];
    }

    /** @return list<array{amount: int|float, percent: int|float}> */
    public static function normalize(mixed $levels): array
    {
        return PercentLevels::normalize($levels, 'amount');
    }
}
