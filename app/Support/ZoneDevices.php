<?php

namespace App\Support;

use App\Models\ClubComputer;
use Illuminate\Support\Collection;

/**
 * Equipment of a zone shown in the guest app. The club fills it in; processor, graphics card and
 * memory fall back to what the PC agents report (the most common model in the zone).
 */
final class ZoneDevices
{
    /** @return array<string, string> key => label */
    public static function fields(): array
    {
        return [
            'cpu' => __('Процессор'),
            'gpu' => __('Видеокарта'),
            'ram' => __('Оперативная память'),
            'monitor' => __('Монитор'),
            'keyboard' => __('Клавиатура'),
            'mouse' => __('Мышь'),
            'headset' => __('Гарнитура'),
            'chair' => __('Кресло'),
        ];
    }

    /**
     * Hardware the agents report, by zone: the most common processor, graphics card and memory.
     *
     * @param  list<int>  $zoneIds
     * @return array<int, array{cpu?: string, gpu?: string, ram?: string}>
     */
    public static function detected(int $clubId, array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }

        $common = fn (string $column): Collection => ClubComputer::query()->withoutGlobalScope('club')
            ->where('club_id', $clubId)
            ->whereIn('club_zone_id', $zoneIds)
            ->whereNotNull($column)
            ->groupBy('club_zone_id', $column)
            ->selectRaw("club_zone_id, {$column} as value, COUNT(*) as pcs")
            ->orderByDesc('pcs')
            ->get()
            ->unique('club_zone_id')
            ->pluck('value', 'club_zone_id');

        $ram = ClubComputer::query()->withoutGlobalScope('club')
            ->where('club_id', $clubId)
            ->whereIn('club_zone_id', $zoneIds)
            ->whereNotNull('ram_total_mb')
            ->groupBy('club_zone_id')
            ->selectRaw('club_zone_id, MAX(ram_total_mb) as value')
            ->pluck('value', 'club_zone_id');

        $detected = [];
        foreach ($common('cpu_model') as $zoneId => $cpu) {
            $detected[$zoneId]['cpu'] = self::cpu($cpu);
        }
        foreach ($common('gpu_model') as $zoneId => $gpu) {
            $detected[$zoneId]['gpu'] = trim($gpu);
        }
        foreach ($ram as $zoneId => $megabytes) {
            $detected[$zoneId]['ram'] = __(':gb ГБ', ['gb' => (int) round($megabytes / 1024)]);
        }

        return $detected;
    }

    /** "11th Gen Intel(R) Core(TM) i5-11400F @ 2.60GHz" → "Intel Core i5-11400F". */
    public static function cpu(string $model): string
    {
        $model = preg_replace(['/\((R|TM)\)/i', '/\bCPU\b/', '/@.*$/', '/^\d+(st|nd|rd|th) Gen\s+/i', '/\s+\d+-Core Processor/i'], '', $model);

        return trim(preg_replace('/\s+/', ' ', $model));
    }
}
