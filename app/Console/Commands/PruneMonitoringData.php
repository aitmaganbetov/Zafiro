<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('monitoring:prune')]
#[Description('Remove old server and club monitoring snapshots')]
class PruneMonitoringData extends Command
{
    public function handle(): int
    {
        $days = (int) config('monitoring.retention_days');
        $minute = intdiv(now()->subDays($days)->getTimestamp(), 60);

        DB::table('server_metrics')->where('minute', '<', $minute)->delete();
        DB::table('request_metrics')->where('minute', '<', $minute)->delete();
        DB::table('club_load_snapshots')->where('captured_at', '<', now()->subDays($days))->delete();
        // Per-club requests are only shown for the last minutes.
        DB::table('club_request_metrics')->where('minute', '<', intdiv(now()->subDays(2)->getTimestamp(), 60))->delete();

        return self::SUCCESS;
    }
}
