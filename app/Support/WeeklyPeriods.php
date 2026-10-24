<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Days and times of a week ([{start_day, start_time, end_day, end_time}], days mon…sun, times
 * H:i), as tariffs keep their validity and sale periods. A period may run across the week's end
 * (Sat 22:00 – Mon 06:00). No periods — always.
 */
final class WeeklyPeriods
{
    private const DAYS = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];

    /** @param  ?array<int, array<string, string>>  $periods */
    public static function contains(?array $periods, Carbon $localNow): bool
    {
        if (! is_array($periods) || $periods === []) {
            return true;
        }

        $minuteOfWeek = ($localNow->dayOfWeekIso - 1) * 1440 + $localNow->hour * 60 + $localNow->minute;
        foreach ($periods as $period) {
            $startDay = self::DAYS[$period['start_day'] ?? ''] ?? null;
            $endDay = self::DAYS[$period['end_day'] ?? ''] ?? null;
            if ($startDay === null || $endDay === null) {
                continue;
            }

            $start = $startDay * 1440 + self::minutes($period['start_time'] ?? '');
            $end = $endDay * 1440 + self::minutes($period['end_time'] ?? '');
            if ($start <= $end ? $minuteOfWeek >= $start && $minuteOfWeek <= $end : $minuteOfWeek >= $start || $minuteOfWeek <= $end) {
                return true;
            }
        }

        return false;
    }

    /** The whole week (Mon 00:00 – Sun 23:59): the same as no limit. */
    public static function isAlways(?array $periods): bool
    {
        return ! is_array($periods) || $periods === [] || collect($periods)->contains(fn (array $period): bool => ($period['start_day'] ?? '') === 'mon' && ($period['start_time'] ?? '') === '00:00'
            && ($period['end_day'] ?? '') === 'sun' && ($period['end_time'] ?? '') === '23:59');
    }

    private static function minutes(string $time): int
    {
        return preg_match('/^(\d{2}):(\d{2})$/', $time, $matches) ? (int) $matches[1] * 60 + (int) $matches[2] : 0;
    }
}
