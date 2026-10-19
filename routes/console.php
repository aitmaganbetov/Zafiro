<?php

use App\Models\AgentQrLogin;
use App\Models\ClubComputer;
use App\Models\SessionPurchase;
use App\Models\TimePackage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Server snapshot and request counters for the cabinet's "Server" page, Telegram alerts. First, so the
// queue worker below (up to 50 s) does not delay it; its rows are also how the scheduler is seen alive.
Schedule::command('monitoring:collect')->everyMinute()->withoutOverlapping(5);
Schedule::command('monitoring:clubs')->everyFiveMinutes()->withoutOverlapping(5);
Schedule::command('monitoring:certificates')->hourly();
Schedule::command('monitoring:prune')->daily();

/*
 * Run by cron every minute: `* * * * * cd /home/admzafiro/Zafiro && php artisan schedule:run`.
 * Webhooks are sent by a short-lived worker, so no daemon has to be supervised.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping(5);

// Expired API tokens (30-day agent and mobile sign-ins) are removed from the database.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Paid PC sessions end on time even when the PC is offline.
Schedule::command('club-computers:expire-sessions')->everyMinute();

Schedule::command('integrations:prune')->hourly()->withoutOverlapping();

// Parts of sessions that are over (refunds are settled when a session ends early) and expired kept time.
Schedule::call(function (): void {
    SessionPurchase::query()->withoutGlobalScope('club')->where('created_at', '<', now()->subDays(8))
        ->whereNotIn('session_id', ClubComputer::query()->withoutGlobalScope('club')->whereNotNull('current_session_id')->select('current_session_id'))
        ->delete();
    TimePackage::query()->withoutGlobalScope('club')->where('expires_at', '<', now())->delete();
})->daily()->name('session-purchases:prune');
// QR codes for signing in on a PC live 5 minutes; old ones are of no use.
Schedule::call(fn () => AgentQrLogin::query()->withoutGlobalScope('club')->where('expires_at', '<', now()->subDay())->delete())
    ->daily()->name('agent-qr-logins:prune');

// Paid club subscriptions: access ends at the paid date at once, the status follows here.
Schedule::command('subscriptions:expire')->everyFiveMinutes()->withoutOverlapping();
