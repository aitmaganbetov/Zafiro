<?php

use App\Support\WeeklyPeriods;
use Illuminate\Support\Carbon;

function weekly(string $startDay, string $startTime, string $endDay, string $endTime): array
{
    return ['start_day' => $startDay, 'start_time' => $startTime, 'end_day' => $endDay, 'end_time' => $endTime];
}

test('the end of the period now is in; periods following each other run on as one', function () {
    $monday = Carbon::parse('2026-10-26 15:00'); // a Monday

    expect(WeeklyPeriods::endOf([weekly('mon', '08:00', 'mon', '18:00')], $monday)?->format('Y-m-d H:i'))->toBe('2026-10-26 18:00')
        ->and(WeeklyPeriods::endOf([weekly('mon', '08:00', 'mon', '18:00'), weekly('mon', '18:00', 'mon', '22:00')], $monday)?->format('Y-m-d H:i'))->toBe('2026-10-26 22:00')
        ->and(WeeklyPeriods::endOf([weekly('mon', '08:00', 'mon', '23:59')], $monday)?->format('Y-m-d H:i'))->toBe('2026-10-27 00:00')
        ->and(WeeklyPeriods::endOf([weekly('sat', '22:00', 'mon', '06:00')], Carbon::parse('2026-10-25 10:00'))?->format('Y-m-d H:i'))->toBe('2026-10-26 06:00')
        ->and(WeeklyPeriods::endOf([weekly('sat', '22:00', 'mon', '06:00')], Carbon::parse('2026-10-26 05:00'))?->format('Y-m-d H:i'))->toBe('2026-10-26 06:00');
});

test('no end without a limit or outside the periods', function () {
    $monday = Carbon::parse('2026-10-26 15:00');

    expect(WeeklyPeriods::endOf(null, $monday))->toBeNull()
        ->and(WeeklyPeriods::endOf([weekly('mon', '00:00', 'sun', '23:59')], $monday))->toBeNull()
        ->and(WeeklyPeriods::endOf([weekly('tue', '08:00', 'tue', '18:00')], $monday))->toBeNull();
});
