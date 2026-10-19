<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * The scheduler writes a server_metrics row every minute. When cron is gone, nothing scheduled
 * runs, so the check is made by requests instead: at most once a minute per server, a file
 * timestamp keeps the others to a single stat() call.
 */
class SchedulerWatchdog
{
    public function __construct(private Alerts $alerts) {}

    public function check(): void
    {
        $marker = config('monitoring.requests_path').'/watchdog';
        if (@filemtime($marker) > time() - 60) {
            return;
        }
        @touch($marker);

        $last = DB::table('server_metrics')->max('minute');
        if ($last === null) {
            return;
        }
        $silent = intdiv(time(), 60) - (int) $last;
        if ($silent > (int) config('monitoring.scheduler_silent_minutes')) {
            $this->alerts->update('scheduler', true,
                fn () => __('Планировщик молчит :minutes мин: вебхуки, окончание сессий и подписок не работают. Проверьте crontab.', ['minutes' => $silent]),
                fn () => '');
        }
    }
}
