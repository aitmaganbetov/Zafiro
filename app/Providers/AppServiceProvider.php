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

        RateLimiter::for('club-computers', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenId = is_object($token) && method_exists($token, 'getKey') ? $token->getKey() : null;

            return Limit::perMinute(60)->by('club-computer-agent:'.($tokenId ?? $request->ip()));
        });

        RateLimiter::for('club-computer-registration', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenId = is_object($token) && method_exists($token, 'getKey') ? $token->getKey() : null;

            return Limit::perMinute(10)->by('club-computer-registration:'.($tokenId ?? $request->ip()));
        });
    }
}
