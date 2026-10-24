<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * A period row added with "+" in the tariff form ended on Monday unless that was changed, so
     * "Thu 10:00 – Mon 17:00" was saved for "Thu 10:00 – 17:00" and the tariff worked at night.
     * Such rows (from another day to Monday, longer than a day) end on their own day again
     * (or the next day when they run over midnight).
     */
    public function up(): void
    {
        foreach (DB::table('tariffs')->get(['id', 'validity_periods', 'sale_periods']) as $tariff) {
            $changes = [];
            foreach (['validity_periods', 'sale_periods'] as $field) {
                $periods = json_decode((string) $tariff->{$field}, true);
                if (! is_array($periods)) {
                    continue;
                }
                $fixed = array_map(fn (array $period): array => $this->fix($period), $periods);
                if ($fixed !== $periods) {
                    $changes[$field] = json_encode($fixed);
                }
            }
            if ($changes !== []) {
                DB::table('tariffs')->where('id', $tariff->id)->update($changes);
            }
        }
    }

    public function down(): void {}

    private function fix(array $period): array
    {
        $start = array_search($period['start_day'] ?? '', self::DAYS, true);
        if (($period['end_day'] ?? '') !== 'mon' || $start === false || $start === 0) {
            return $period;
        }
        $startMinute = $start * 1440 + $this->minutes($period['start_time'] ?? '');
        $endMinute = 7 * 1440 + $this->minutes($period['end_time'] ?? '');
        if ($endMinute - $startMinute <= 1440) {
            return $period;
        }

        $overMidnight = $this->minutes($period['end_time'] ?? '') <= $this->minutes($period['start_time'] ?? '');
        $period['end_day'] = self::DAYS[($start + ($overMidnight ? 1 : 0)) % 7];

        return $period;
    }

    private function minutes(string $time): int
    {
        return preg_match('/^(\d{2}):(\d{2})$/', $time, $m) ? (int) $m[1] * 60 + (int) $m[2] : 0;
    }
};
