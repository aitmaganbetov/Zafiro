<?php

namespace App\Support;

/**
 * Data of a small inline SVG line chart (no chart library on the page): one polyline per series
 * in a 0..WIDTH × 0..HEIGHT box, broken where a bucket has no value.
 */
class LineChart
{
    public const WIDTH = 600;

    public const HEIGHT = 160;

    private const COLORS = ['#7c3aed', '#0ea5e9', '#f59e0b', '#10b981', '#ef4444', '#ec4899', '#14b8a6', '#64748b'];

    /**
     * @param  list<int>  $buckets  the buckets (Unix minutes), in order
     * @param  array<string, array<int, float|int|null>>  $series  label => [bucket => value]
     * @return array{lines: list<array{label: string, color: string, segments: list<string>}>, max: float, from: int|null, to: int|null}
     */
    public static function make(array $buckets, array $series, ?float $max = null): array
    {
        $values = collect($series)->flatten()->filter(fn ($value) => $value !== null);
        $max ??= max(1.0, (float) ($values->max() ?? 0) * 1.1);
        $step = count($buckets) > 1 ? self::WIDTH / (count($buckets) - 1) : 0;

        $lines = [];
        $index = 0;
        foreach ($series as $label => $points) {
            $segments = [];
            $current = [];
            foreach ($buckets as $position => $bucket) {
                $value = $points[$bucket] ?? null;
                if ($value === null) {
                    $current !== [] && $segments[] = implode(' ', $current);
                    $current = [];

                    continue;
                }
                $y = self::HEIGHT - min(1, $value / $max) * self::HEIGHT;
                $current[] = round($position * $step, 1).','.round($y, 1);
            }
            $current !== [] && $segments[] = implode(' ', $current);
            $lines[] = ['label' => (string) $label, 'color' => self::COLORS[$index++ % count(self::COLORS)], 'segments' => $segments];
        }

        return ['lines' => $lines, 'max' => $max, 'from' => $buckets[0] ?? null, 'to' => $buckets[count($buckets) - 1] ?? null];
    }
}
