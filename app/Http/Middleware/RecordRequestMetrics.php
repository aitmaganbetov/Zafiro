<?php

namespace App\Http\Middleware;

use App\Services\CurrentClub;
use App\Services\Monitoring\RequestRecorder;
use App\Services\Monitoring\SchedulerWatchdog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Counts every request for the cabinet's "Server" page; the work is done after the response is sent. */
class RecordRequestMetrics
{
    public function __construct(private RequestRecorder $recorder, private SchedulerWatchdog $watchdog, private CurrentClub $currentClub) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('monitoring.record_requests')) {
            return;
        }

        try {
            $this->recorder->record($request, $response, $this->currentClub->id());
            // The scheduler cannot report its own silence: requests check on it instead.
            $this->watchdog->check();
        } catch (Throwable) {
            // Monitoring never breaks a request.
        }
    }
}
