<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;

/** Reads the state of the machine, the database and the queue (Linux /proc, MySQL information_schema). */
class ServerProbe
{
    /** @return array<string, int|float|null> a server_metrics row without `minute` */
    public function snapshot(): array
    {
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: []) : [];
        $memory = $this->memory();
        $diskTotal = @disk_total_space(base_path());
        $diskFree = @disk_free_space(base_path());

        return [
            'load_1' => isset($load[0]) ? round($load[0], 2) : null,
            'load_5' => isset($load[1]) ? round($load[1], 2) : null,
            'load_15' => isset($load[2]) ? round($load[2], 2) : null,
            'cpu_count' => $this->cpuCount(),
            'memory_total_mb' => $memory['total'],
            'memory_used_mb' => $memory['used'],
            'disk_total_mb' => $diskTotal ? (int) ($diskTotal / 1048576) : null,
            'disk_used_mb' => $diskTotal && $diskFree !== false ? (int) (($diskTotal - $diskFree) / 1048576) : null,
            'uptime_seconds' => $this->uptime(),
            'database_mb' => $this->databaseMegabytes(),
            ...$this->queue(),
        ];
    }

    /** @return array{queue_pending: int, queue_failed: int, queue_oldest_seconds: int|null} */
    public function queue(): array
    {
        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');

        return [
            'queue_pending' => DB::table('jobs')->count(),
            'queue_failed' => DB::table('failed_jobs')->count(),
            'queue_oldest_seconds' => $oldest !== null ? max(0, time() - (int) $oldest) : null,
        ];
    }

    /** @return list<object{name: string, megabytes: float, rows: int}> biggest tables first (MySQL only) */
    public function biggestTables(int $limit = 8): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return [];
        }

        return DB::select('SELECT table_name AS name, ROUND((data_length + index_length) / 1048576, 1) AS megabytes, table_rows AS `rows`
            FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC LIMIT ?', [$limit]);
    }

    private function databaseMegabytes(): ?int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return null;
        }
        $bytes = DB::scalar('SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()');

        return $bytes !== null ? (int) round($bytes / 1048576) : null;
    }

    /** @return array{total: int|null, used: int|null} megabytes; "used" leaves out reclaimable cache */
    private function memory(): array
    {
        $info = @file_get_contents('/proc/meminfo');
        if (! is_string($info) || ! preg_match('/^MemTotal:\s+(\d+)/m', $info, $total) || ! preg_match('/^MemAvailable:\s+(\d+)/m', $info, $available)) {
            return ['total' => null, 'used' => null];
        }

        return ['total' => intdiv((int) $total[1], 1024), 'used' => intdiv((int) $total[1] - (int) $available[1], 1024)];
    }

    private function cpuCount(): ?int
    {
        $info = @file_get_contents('/proc/cpuinfo');
        $count = is_string($info) ? preg_match_all('/^processor\s*:/m', $info) : 0;

        return $count > 0 ? $count : null;
    }

    private function uptime(): ?int
    {
        $uptime = @file_get_contents('/proc/uptime');

        return is_string($uptime) && is_numeric(strtok($uptime, ' ')) ? (int) strtok($uptime, ' ') : null;
    }
}
