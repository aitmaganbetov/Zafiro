<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Club;
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
use Illuminate\Support\Collection;
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

    /** Clubs in the load table (the heaviest first) and lines on its chart. */
    private const CLUB_LOAD_ROWS = 50;

    private const CLUB_LOAD_LINES = 8;

    private const CLUB_LOAD_DEFAULT_LINES = 5;

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

        $clubLoad = $this->clubLoad($request, $from, $bucket, $bucketSql);

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
                'clubs' => $chart($clubLoad['series']),
            ],
            'clubLoad' => $clubLoad['rows'],
            'clubNames' => $clubLoad['names'],
            'selectedClubs' => $clubLoad['selected'],
            'periodMinutes' => $length,
            'serverDurationMs' => (int) $requests->sum('duration_ms'),
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

    /**
     * Load of each club for the period (its PCs, API keys, staff panel and guests' app), the
     * heaviest first, and requests per minute of the selected clubs for the chart.
     *
     * @return array{rows: Collection<int, object>, names: array<int, string>, selected: list<int>, series: array<string, array<int, float>>}
     */
    private function clubLoad(Request $request, int $from, int $bucket, string $bucketSql): array
    {
        $total = 'agent_requests + api_requests + panel_requests + guest_requests';
        $rows = DB::table('club_request_metrics')->where('minute', '>=', $from)
            ->selectRaw('club_id, SUM(agent_requests) AS agent, SUM(api_requests) AS api, SUM(panel_requests) AS panel, SUM(guest_requests) AS guest')
            ->selectRaw("SUM({$total}) AS requests, MAX({$total}) AS peak, SUM(errors) AS errors, SUM(duration_ms) AS duration_ms")
            ->groupBy('club_id')->orderByDesc('requests')->limit(self::CLUB_LOAD_ROWS)->get();

        $asked = collect(is_array($request->query('clubs')) ? $request->query('clubs') : [])
            ->filter(fn (mixed $id): bool => is_string($id) && ctype_digit($id))->map(fn (string $id): int => (int) $id)->unique()->values();
        $selected = ($asked->isNotEmpty() ? $asked : $rows->pluck('club_id')->map(fn ($id): int => (int) $id)->take(self::CLUB_LOAD_DEFAULT_LINES))
            ->take(self::CLUB_LOAD_LINES)->values();

        $names = Club::query()->whereKey($rows->pluck('club_id')->merge($selected)->unique()->all())->pluck('name', 'id')->all();
        $points = $selected->isEmpty() ? collect() : DB::table('club_request_metrics')->where('minute', '>=', $from)->whereIn('club_id', $selected->all())
            ->selectRaw($bucketSql." AS bucket, club_id, SUM({$total}) AS requests")
            ->groupByRaw($bucketSql)->groupBy('club_id')->get();

        $series = [];
        foreach ($selected as $id) {
            $label = $names[$id] ?? '#'.$id;
            // Two clubs of one name stay two lines.
            $label = array_key_exists($label, $series) ? $label.' #'.$id : $label;
            $series[$label] = $points->where('club_id', $id)->mapWithKeys(fn ($row) => [$row->bucket => round($row->requests / $bucket, 1)])->all();
        }

        return ['rows' => $rows, 'names' => $names, 'selected' => $selected->all(), 'series' => $series];
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
