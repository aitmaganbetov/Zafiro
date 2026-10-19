<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\ClubComputer;
use App\Models\PlatformLog;
use App\Models\PlatformSetting;
use App\Services\AgentUpdate;
use App\Services\Monitoring\Certificates;
use App\Services\Monitoring\ErrorLog;
use App\Services\Monitoring\RequestRecorder;
use App\Services\Monitoring\ServerProbe;
use App\Support\LineChart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "Server": is the server alive and has it enough resources. The machine and the queue are read
 * live; history comes from the snapshots the scheduler saves every minute (monitoring:collect).
 */
class DashboardController extends Controller
{
    /** period => [length in minutes, bucket in minutes] */
    private const PERIODS = ['24h' => [1440, 10], '7d' => [10080, 60]];

    public function __invoke(Request $request, AgentUpdate $agentUpdate, ServerProbe $probe, ErrorLog $errorLog, Certificates $certificates): View
    {
        $period = in_array($request->query('period'), array_keys(self::PERIODS), true) ? $request->query('period') : '24h';
        [$length, $bucket] = self::PERIODS[$period];
        $now = intdiv(now()->getTimestamp(), 60);
        $from = $now - $length;
        $buckets = range($from - $from % $bucket, $now - $now % $bucket, $bucket);
        $bucketSql = 'minute - minute % '.$bucket;

        $server = DB::table('server_metrics')->where('minute', '>=', $from)
            ->selectRaw($bucketSql.' AS bucket, AVG(load_1) AS load_1, AVG(memory_used_mb * 100.0 / memory_total_mb) AS memory')
            ->groupByRaw($bucketSql)->get()->keyBy('bucket');
        $requests = DB::table('request_metrics')->where('minute', '>=', $from)
            ->selectRaw($bucketSql.' AS bucket, channel, SUM(requests) AS requests, SUM(errors) AS errors, SUM(duration_ms) AS duration_ms')
            ->groupByRaw($bucketSql)->groupBy('channel')->get();
        $errorsByBucket = DB::table('request_metrics')->where('minute', '>=', $from)
            ->selectRaw($bucketSql.' AS bucket, SUM(requests) AS requests, SUM(errors) AS errors')
            ->groupByRaw($bucketSql)->get()->keyBy('bucket');

        $channelLabels = self::channelLabels();
        $perChannel = fn (callable $value) => collect(RequestRecorder::CHANNELS)->mapWithKeys(fn (string $channel) => [
            $channelLabels[$channel] => $requests->where('channel', $channel)->mapWithKeys(fn ($row) => [$row->bucket => $value($row)])->all(),
        ])->all();
        $chart = fn (array $series, ?float $max = null) => LineChart::make($buckets, $series, $max);

        $latest = DB::table('server_metrics')->orderByDesc('minute')->first();
        $live = $probe->snapshot();

        return view('superadmin.dashboard', [
            'period' => $period,
            'live' => $live,
            'schedulerMinutesAgo' => $latest !== null ? $now - (int) $latest->minute : null,
            'charts' => [
                'load' => $chart([__('Нагрузка CPU') => $server->map(fn ($row) => $row->load_1 !== null ? round((float) $row->load_1, 2) : null)->all()], max((float) ($live['cpu_count'] ?? 1), (float) $server->max('load_1')) * 1.1),
                'memory' => $chart([__('Память, %') => $server->map(fn ($row) => $row->memory !== null ? round((float) $row->memory, 1) : null)->all()], 100),
                'requests' => $chart($perChannel(fn ($row) => round($row->requests / $bucket, 1))),
                'duration' => $chart($perChannel(fn ($row) => $row->requests > 0 ? (int) round($row->duration_ms / $row->requests) : null)),
                'errors' => $chart([__('Ошибки 5xx, %') => $errorsByBucket->map(fn ($row) => $row->requests > 0 ? round($row->errors * 100 / $row->requests, 2) : 0)->all()]),
            ],
            'channels' => DB::table('request_metrics')->where('minute', '>=', $now - 15)
                ->selectRaw('channel, SUM(requests) AS requests, SUM(errors) AS errors, SUM(duration_ms) AS duration_ms')
                ->groupBy('channel')->get()->keyBy('channel'),
            'channelLabels' => $channelLabels,
            'tables' => $probe->biggestTables(),
            'logErrors' => $errorLog->recent(),
            'certificates' => $certificates->cached(),
            'certificateHosts' => $certificates->hosts(),
            'backup' => $this->lastBackup(),
            'computers' => ClubComputer::query()->withoutGlobalScope('club')
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) AS online', [now()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS)])
                ->first(),
            'agentVersions' => ClubComputer::query()->withoutGlobalScope('club')
                ->whereNotNull('agent_version')
                ->selectRaw('agent_version, COUNT(*) AS aggregate')
                ->groupBy('agent_version')->orderByDesc('aggregate')->limit(10)
                ->pluck('aggregate', 'agent_version'),
            'latestAgent' => $agentUpdate->latestVersion(),
            'agentRelease' => PlatformSetting::read(PlatformSetting::AGENT_RELEASE),
            'agentDownloadUrl' => $agentUpdate->downloadUrl(),
            'recentLogs' => PlatformLog::query()->with(['user:id,name', 'club:id,name'])->latest('id')->limit(10)->get(),
        ]);
    }

    /** @return array<string, string> */
    public static function channelLabels(): array
    {
        return ['panel' => __('Панель'), 'agent' => __('Агенты ПК'), 'api' => __('API v1'), 'guest' => __('Приложение гостя')];
    }

    /** @return array{configured: bool, at: int|null} time of the newest file in BACKUP_PATH */
    private function lastBackup(): array
    {
        $path = config('monitoring.backup_path');
        if (! is_string($path) || $path === '' || ! is_dir($path)) {
            return ['configured' => false, 'at' => null];
        }
        $times = array_map(fn (string $file) => (int) @filemtime($file), array_filter(glob(rtrim($path, '/').'/*') ?: [], 'is_file'));

        return ['configured' => true, 'at' => $times !== [] ? max($times) : null];
    }
}
