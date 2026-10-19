<?php

namespace App\Console\Commands;

use App\Services\Monitoring\Alerts;
use App\Services\Monitoring\RequestRecorder;
use App\Services\Monitoring\ServerProbe;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('monitoring:collect')]
#[Description('Save a server snapshot and the request counters of finished minutes, send alerts')]
class CollectServerMetrics extends Command
{
    public function handle(RequestRecorder $recorder, ServerProbe $probe, Alerts $alerts): int
    {
        $minute = intdiv(now()->getTimestamp(), 60);
        $this->saveRequests($recorder->drain($minute));

        $snapshot = $probe->snapshot();
        DB::table('server_metrics')->insertOrIgnore(['minute' => $minute, ...$snapshot]);

        // This command is run by the scheduler, so the scheduler is alive again.
        $alerts->update('scheduler', false, fn () => '', fn () => __('Планировщик снова работает.'));
        $this->checkDisk($snapshot, $alerts);
        $this->checkErrors($minute, $alerts);
        $this->checkQueue($snapshot, $alerts);

        return self::SUCCESS;
    }

    /** @param  array{channels: array<int, array<string, array{requests: int, errors: int, duration_ms: int}>>, clubs: array<int, array<int, array{agent?: int, api?: int}>>}  $drained */
    private function saveRequests(array $drained): void
    {
        foreach ($drained['channels'] as $minute => $channels) {
            foreach ($channels as $channel => $totals) {
                // A line written just after its minute was read comes a minute later: it is added up.
                $updated = DB::table('request_metrics')->where('minute', $minute)->where('channel', $channel)->update([
                    'requests' => DB::raw('requests + '.(int) $totals['requests']),
                    'errors' => DB::raw('errors + '.(int) $totals['errors']),
                    'duration_ms' => DB::raw('duration_ms + '.(int) $totals['duration_ms']),
                ]);
                if ($updated === 0) {
                    DB::table('request_metrics')->insert(['minute' => $minute, 'channel' => $channel, ...$totals]);
                }
            }
        }

        $known = DB::table('clubs')->pluck('id')->flip();
        foreach ($drained['clubs'] as $minute => $clubs) {
            $existing = DB::table('club_request_metrics')->where('minute', $minute)->pluck('club_id')->flip();
            $rows = [];
            foreach ($clubs as $clubId => $counts) {
                if (! $known->has($clubId)) {
                    continue;
                }
                if ($existing->has($clubId)) {
                    DB::table('club_request_metrics')->where('club_id', $clubId)->where('minute', $minute)->update([
                        'agent_requests' => DB::raw('agent_requests + '.(int) ($counts['agent'] ?? 0)),
                        'api_requests' => DB::raw('api_requests + '.(int) ($counts['api'] ?? 0)),
                    ]);
                } else {
                    $rows[] = ['club_id' => $clubId, 'minute' => $minute, 'agent_requests' => $counts['agent'] ?? 0, 'api_requests' => $counts['api'] ?? 0];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('club_request_metrics')->insert($chunk);
            }
        }
    }

    /** @param  array<string, mixed>  $snapshot */
    private function checkDisk(array $snapshot, Alerts $alerts): void
    {
        if (! $snapshot['disk_total_mb']) {
            return;
        }
        $percent = (int) round($snapshot['disk_used_mb'] * 100 / $snapshot['disk_total_mb']);
        $alerts->update('disk', $percent > (int) config('monitoring.disk_percent'),
            fn () => __('Диск сервера заполнен на :percent%.', ['percent' => $percent]),
            fn () => __('Место на диске освободилось: занято :percent%.', ['percent' => $percent]));
    }

    private function checkErrors(int $minute, Alerts $alerts): void
    {
        $totals = DB::table('request_metrics')->where('minute', '>=', $minute - 5)
            ->selectRaw('COALESCE(SUM(requests), 0) AS requests, COALESCE(SUM(errors), 0) AS errors')->first();
        $percent = $totals->requests > 0 ? round($totals->errors * 100 / $totals->requests, 1) : 0;
        $failing = $totals->errors >= (int) config('monitoring.error_rate_min_errors') && $percent >= (float) config('monitoring.error_rate_percent');
        $alerts->update('errors', $failing,
            fn () => __('Ошибки 5xx: :percent% запросов за 5 минут (:errors из :requests).', ['percent' => $percent, 'errors' => (int) $totals->errors, 'requests' => (int) $totals->requests]),
            fn () => __('Доля ошибок 5xx вернулась к норме.'));
    }

    /** @param  array<string, mixed>  $snapshot */
    private function checkQueue(array $snapshot, Alerts $alerts): void
    {
        $failing = $snapshot['queue_pending'] >= (int) config('monitoring.queue_pending')
            || ($snapshot['queue_oldest_seconds'] ?? 0) >= (int) config('monitoring.queue_oldest_minutes') * 60;
        $alerts->update('queue', $failing,
            fn () => __('Копится очередь: :count заданий ждут, самое старое — :minutes мин.', ['count' => $snapshot['queue_pending'], 'minutes' => intdiv((int) $snapshot['queue_oldest_seconds'], 60)]),
            fn () => __('Очередь разобрана.'));
    }
}
