<?php

namespace App\Services\Monitoring;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Sends an alert once when a problem starts and once when it is over, not every minute while it
 * lasts. Which problems are open is kept in the cache (shared by the web server and the scheduler).
 */
class Alerts
{
    public function __construct(private TelegramAlerts $telegram) {}

    /**
     * @param  Closure(): string  $problem  text of the alert, built in the alerts' language
     * @param  Closure(): string  $recovered  text when the problem is over
     */
    public function update(string $key, bool $failing, Closure $problem, Closure $recovered): void
    {
        $cacheKey = 'monitoring.alert.'.$key;
        $open = Cache::has($cacheKey);
        if ($failing === $open) {
            return;
        }

        if ($failing) {
            Cache::forever($cacheKey, now()->getTimestamp());
        } else {
            Cache::forget($cacheKey);
        }

        if (! $this->telegram->configured()) {
            return;
        }
        $locale = app()->getLocale();
        app()->setLocale($this->telegram->locale());
        try {
            $this->telegram->send(($failing ? '⚠️ ' : '✅ ').($failing ? $problem() : $recovered()));
        } finally {
            app()->setLocale($locale);
        }
    }

    public function isOpen(string $key): bool
    {
        return Cache::has('monitoring.alert.'.$key);
    }
}
