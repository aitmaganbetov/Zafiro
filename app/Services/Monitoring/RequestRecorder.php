<?php

namespace App\Services\Monitoring;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request counters without a database write per request: PC agents send heartbeats very often,
 * so each request appends one line (channel, club, time, status) to the file of its minute,
 * and monitoring:collect folds finished minutes into request_metrics once a minute.
 */
class RequestRecorder
{
    public const CHANNELS = ['panel', 'agent', 'api', 'guest'];

    public function record(Request $request, Response $response, ?int $clubId): void
    {
        $started = defined('LARAVEL_START') ? LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
        $milliseconds = max(0, (int) round((microtime(true) - $started) * 1000));
        $line = implode("\t", [self::channel($request), $clubId ?? '-', $milliseconds, $response->getStatusCode()])."\n";

        $directory = (string) config('monitoring.requests_path');
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        @file_put_contents($directory.'/'.self::fileName(intdiv(time(), 60)), $line, FILE_APPEND | LOCK_EX);
    }

    public static function channel(Request $request): string
    {
        $name = (string) $request->route()?->getName();

        return match (true) {
            str_starts_with($name, 'api.v1.') || $request->is('api/v1', 'api/v1/*') => 'api',
            str_starts_with($name, 'api.agent.') || str_starts_with($name, 'api.club-computers.') || $name === 'api.clubs.resolve' => 'agent',
            $request->is('api/*') => 'guest',
            default => 'panel',
        };
    }

    public static function fileName(int $minute): string
    {
        return 'requests-'.$minute.'.log';
    }

    /**
     * Totals of the files whose minute is over (a minute is kept open a little longer, so a
     * request still writing its line is not lost). The files are removed once read.
     *
     * @return array{channels: array<int, array<string, array{requests: int, errors: int, duration_ms: int}>>, clubs: array<int, array<int, array{agent: int, api: int}>>}
     */
    public function drain(int $beforeMinute): array
    {
        $channels = [];
        $clubs = [];
        foreach (glob(config('monitoring.requests_path').'/requests-*.log') ?: [] as $file) {
            $minute = (int) substr(basename($file, '.log'), strlen('requests-'));
            if ($minute >= $beforeMinute) {
                continue;
            }
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $parts = explode("\t", rtrim($line, "\n"));
                if (count($parts) !== 4 || ! in_array($parts[0], self::CHANNELS, true)) {
                    continue;
                }
                [$channel, $club, $milliseconds, $status] = $parts;
                $totals = $channels[$minute][$channel] ?? ['requests' => 0, 'errors' => 0, 'duration_ms' => 0];
                $totals['requests']++;
                $totals['errors'] += (int) $status >= 500 ? 1 : 0;
                $totals['duration_ms'] += (int) $milliseconds;
                $channels[$minute][$channel] = $totals;
                if (ctype_digit($club) && in_array($channel, ['agent', 'api'], true)) {
                    $clubs[$minute][(int) $club][$channel] = ($clubs[$minute][(int) $club][$channel] ?? 0) + 1;
                }
            }
            fclose($handle);
            @unlink($file);
        }

        return ['channels' => $channels, 'clubs' => $clubs];
    }
}
