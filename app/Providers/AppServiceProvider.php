<?php

namespace App\Providers;

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\User;
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
        // Languages and translations are shared by every club, so only the platform superadmin edits them.
        Gate::define('manage-translations', fn (User $user): bool => $user->isSuperadmin());
        Gate::define('manage-integrations', fn (): bool => app(CurrentClub::class)->membership()?->canManageIntegrations() === true);

        $this->invalidateApiKeyCache();

        RateLimiter::for('club-computers', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('club-computer-agent:'.$this->agentRateLimitKey($request)));

        // Staff PIN on a PC: a handful of tries per PC, so a short PIN cannot be guessed.
        // A diskless club restarts all its PCs at once behind one IP: limit each PC, and the IP loosely.
        RateLimiter::for('agent-enroll', fn (Request $request): array => [
            Limit::perMinute(5)->by('agent-enroll:'.$request->ip().'|'.hash('sha256', mb_strtolower((string) $request->input('device_id')))),
            Limit::perMinute(300)->by('agent-enroll-ip:'.$request->ip()),
        ]);

        RateLimiter::for('agent-exit-pin', fn (Request $request): array => [
            Limit::perMinutes(5, 5)->by('agent-exit-pin:'.$this->agentRateLimitKey($request)),
            // Общий лимит на клуб: подбор с нескольких ПК сразу не ускоряется. Он выше лимита
            // одного ПК (60 в час), чтобы клиент одного ПК не мог заблокировать выход сотрудникам клуба.
            Limit::perHour(100)->by('agent-exit-pin-club:'.($request->attributes->get('agent_computer')?->club_id ?? $request->ip())),
        ]);

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
            if ($club->wasChanged(['name', 'slug', 'locale', 'timezone', 'settings'])) {
                $keys()->forgetClub((int) $club->getKey());
            }
        });
    }

    private function agentRateLimitKey(Request $request): string
    {
        $device = $request->header('X-Agent-Token');

        if (is_string($device) && $device !== '') {
            return 'device:'.hash('sha256', $device);
        }

        $token = $request->user()?->currentAccessToken();
        $tokenId = is_object($token) && method_exists($token, 'getKey') ? $token->getKey() : null;

        return (string) ($tokenId ?? $request->ip());
    }
}
