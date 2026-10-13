<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('integrations:prune')]
#[Description('Delete expired API request logs, idempotency keys and finished webhook deliveries')]
class PruneIntegrationData extends Command
{
    public function handle(): int
    {
        $deleted = [
            'api_request_logs' => $this->prune(DB::table('api_request_logs')->where('created_at', '<', now()->subDays((int) config('integrations.request_log_retention_days', 90)))),
            'api_idempotency_keys' => $this->prune(DB::table('api_idempotency_keys')->where('created_at', '<', now()->subHours((int) config('integrations.idempotency_retention_hours', 24)))),
            'webhook_deliveries' => $this->prune(DB::table('webhook_deliveries')
                ->whereIn('status', ['delivered', 'failed', 'skipped'])
                ->where('created_at', '<', now()->subDays((int) config('integrations.webhook_delivery_retention_days', 30)))),
        ];

        foreach ($deleted as $table => $count) {
            $this->line("{$table}: {$count}");
        }

        return self::SUCCESS;
    }

    /** Deletes in batches so a large backlog never holds long locks. */
    private function prune($query): int
    {
        $total = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit(1000)->pluck('id');
            $total += $ids->isEmpty() ? 0 : DB::table($query->from)->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        return $total;
    }
}
