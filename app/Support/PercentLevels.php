<?php

namespace App\Support;

/**
 * Levels "from a threshold — a percent" of the finance settings (cumulative discount: spent;
 * top-up bonus: amount): rows with an empty field dropped, numbers rounded to the cent, sorted.
 */
final class PercentLevels
{
    /** @return list<array<string, int|float>> [{$threshold, percent}] by the threshold ascending */
    public static function normalize(mixed $levels, string $threshold): array
    {
        if (! is_array($levels)) {
            return [];
        }

        $number = fn (mixed $value): int|float => floor($rounded = round((float) $value, 2)) === $rounded ? (int) $rounded : $rounded;

        return collect($levels)
            ->filter(fn (mixed $level): bool => is_array($level) && is_numeric($level[$threshold] ?? null) && is_numeric($level['percent'] ?? null))
            ->map(fn (array $level): array => [$threshold => $number($level[$threshold]), 'percent' => $number($level['percent'])])
            ->sortBy($threshold)
            ->values()
            ->all();
    }

    /** The percent of the highest level the value reaches, in cents; 0 — none. */
    public static function percentAt(array $levels, string $threshold, int $cents): float
    {
        $percent = 0.0;
        foreach ($levels as $level) {
            if ($cents >= (int) round($level[$threshold] * 100)) {
                $percent = max($percent, (float) $level['percent']);
            }
        }

        return $percent;
    }
}
