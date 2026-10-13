<?php

namespace App\Providers;

use App\Models\ClubComputer;
use App\Policies\ClubComputerPolicy;
use App\Services\CurrentClub;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentClub::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ClubComputer::class, ClubComputerPolicy::class);

        RateLimiter::for('club-computers', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('club-computer-agent:'.$this->agentRateLimitKey($request)));

        RateLimiter::for('club-computer-registration', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('club-computer-registration:'.$this->agentRateLimitKey($request)));
    }

    private function agentRateLimitKey(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        $tokenId = is_object($token) && method_exists($token, 'getKey') ? $token->getKey() : null;

        return (string) ($tokenId ?? $request->ip());
    }
}
