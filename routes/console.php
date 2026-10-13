<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Run by cron every minute: `* * * * * cd /home/admzafiro/Zafiro && php artisan schedule:run`.
 * Webhooks are sent by a short-lived worker, so no daemon has to be supervised.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('integrations:prune')->dailyAt('04:30');
