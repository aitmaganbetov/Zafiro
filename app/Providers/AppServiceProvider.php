<?php

namespace App\Providers;

use App\Http\Middleware\Api\AuthenticateApiKey;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\WebhookEndpoint;
use App\Policies\ClubComputerPolicy;
use App\Services\CurrentClub;
use App\Services\Integrations\ApiKeyManager;
use App\Services\Integrations\WebhookDispatcher;
use App\Services\LocaleResolver;
use App\Services\TranslationCatalog;
use App\Translation\DatabaseTranslationLoader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Translation\Loader;
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
        $this->app->singleton(TranslationCatalog::class);
        $this->app->scoped(LocaleResolver::class);
        $this->app->extend('translation.loader', fn (Loader $files, $app): Loader => new DatabaseTranslationLoader($files, $app->make(TranslationCatalog::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ClubComputer::class, ClubComputerPolicy::class);

        // Integration API: one bucket per key and one per club, so several keys of a
        // club cannot multiply its share of the server.
        RateLimiter::for('api-v1', function (Request $request): array {
            $key = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);

            return [
                Limit::perMinute(min((int) ($key['rate_limit_per_minute'] ?? config('integrations.default_rate_limit_per_minute')), (int) config('integrations.max_rate_limit_per_minute')))
                    ->by('api-v1-key:'.($key['id'] ?? $request->ip())),
                Limit::perMinute((int) config('integrations.club_rate_limit_per_minute'))
                    ->by('api-v1-club:'.($key['club_id'] ?? $request->ip())),
            ];
        });

        $this->invalidateApiKeyCache();

        RateLimiter::for('club-computers', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('club-computer-agent:'.$this->agentRateLimitKey($request)));

        RateLimiter::for('club-computer-registration', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('club-computer-registration:'.$this->agentRateLimitKey($request)));
    }

    /** Cached API key metadata depends on these records; drop it as soon as they change. */
    private function invalidateApiKeyCache(): void
    {
        $keys = fn (): ApiKeyManager => $this->app->make(ApiKeyManager::class);

        $membershipChanged = function (ClubMembership $membership) use ($keys): void {
            if (! $membership->exists || $membership->wasChanged(['role', 'can_manage_integrations', 'user_id', 'club_id'])) {
                $keys()->forgetKeysOf((int) $membership->user_id, (int) $membership->club_id);
            }
        };
        ClubMembership::saved($membershipChanged);
        ClubMembership::deleted($membershipChanged);

        ClubSubscription::saved(fn (ClubSubscription $subscription) => $keys()->forgetClub((int) $subscription->club_id));
        ClubSubscription::deleted(fn (ClubSubscription $subscription) => $keys()->forgetClub((int) $subscription->club_id));
        $webhooks = fn (WebhookEndpoint $endpoint) => $this->app->make(WebhookDispatcher::class)->forgetClub((int) $endpoint->club_id);
        WebhookEndpoint::saved($webhooks);
        WebhookEndpoint::deleted($webhooks);

        Club::saved(function (Club $club) use ($keys): void {
            if ($club->wasChanged(['name', 'slug', 'locale', 'settings'])) {
                $keys()->forgetClub((int) $club->getKey());
            }
        });
    }

    private function agentRateLimitKey(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        $tokenId = is_object($token) && method_exists($token, 'getKey') ? $token->getKey() : null;

        return (string) ($tokenId ?? $request->ip());
    }
}
