<?php

namespace App\Http\Middleware;

use App\Models\Club;
use App\Models\ClubMembership;
use App\Services\CurrentClub;
use App\Services\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentClub
{
    public function __construct(private LocaleResolver $locales) {}

    /** Session key: the club a superadmin is viewing (read-only). */
    public const VIEWING_KEY = 'superadmin_viewing_club_id';

    public function handle(Request $request, Closure $next): Response
    {
        $currentClub = app(CurrentClub::class);
        $user = $request->user();

        // A blocked account loses access at once, even with a live session or API token.
        if ($user->isBlocked()) {
            if ($request->is('api/*')) {
                abort(403, __('Аккаунт заблокирован.'));
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('auth.show-login')->withErrors(['phone' => __('Аккаунт заблокирован. Обратитесь в поддержку Zafiro.')]);
        }

        if (! $request->is('api/*') && $user->isSuperadmin() && $request->session()->has(self::VIEWING_KEY)) {
            $viewing = $this->viewAsSuperadmin($request, $currentClub);
            if ($viewing !== null) {
                return $viewing === true ? $next($request) : $viewing;
            }
        }

        $memberships = ClubMembership::query()
            ->withoutGlobalScope('club')
            ->with('club.subscription')
            ->where('user_id', $request->user()->getKey())
            ->get();

        // Messages are translated only when the request is actually rejected.
        if ($memberships->isEmpty()) {
            // A superadmin without a club of their own works from the cabinet.
            if ($user->isSuperadmin() && ! $request->is('api/*')) {
                return redirect()->route('superadmin.dashboard');
            }

            abort(403, __('У аккаунта нет доступа ни к одному клубу.'));
        }

        if ($request->is('api/*')) {
            $clubHeader = $request->header('X-Club-Id');
            $token = $request->user()->currentAccessToken();
            $clubAbility = collect($token?->abilities ?? [])
                ->first(fn (string $ability): bool => str_starts_with($ability, 'club:'));
            $tokenClubId = $clubAbility ? (int) substr($clubAbility, 5) : null;
            if ($clubHeader && $tokenClubId !== null) {
                if ((int) $clubHeader !== $tokenClubId) {
                    abort(403, __('API-токен привязан к другому клубу.'));
                }
            }
            $requestedClubId = $clubHeader ?: ($clubAbility ? substr($clubAbility, 5) : null);
        } else {
            $requestedClubId = $request->session()->get('current_club_id');
        }

        $membership = $requestedClubId
            ? $memberships->firstWhere('club_id', (int) $requestedClubId)
            : $memberships->first();

        if ($membership === null) {
            abort(403, __('Нет доступа к выбранному клубу.'));
        }

        $currentClub->set($membership->club, $membership);

        if ($request->is('api/*')) {
            $currentClub->useUtc();
        }
        $this->locales->apply($this->locales->forClub($membership->club));

        if (! $request->is('api/*')) {
            $request->session()->put('current_club_id', $membership->club_id);
            View::share('currentClub', $membership->club);
            View::share('currentMembership', $membership);
            View::share('availableClubs', $memberships);
        }

        return $next($request);
    }

    /**
     * Superadmin viewing a club: its panel as the owner sees it, but read-only. Any change
     * (not GET/HEAD) is refused, except leaving the view and signing out.
     *
     * @return true|Response|null true to continue, a response to stop, null when the club is gone
     */
    private function viewAsSuperadmin(Request $request, CurrentClub $currentClub): true|Response|null
    {
        $club = Club::query()->with('subscription')->find($request->session()->get(self::VIEWING_KEY));
        if ($club === null) {
            $request->session()->forget(self::VIEWING_KEY);

            return null;
        }

        if (! $request->isMethodSafe() && ! $request->routeIs('superadmin.*', 'auth.logout')) {
            abort(403, __('Режим просмотра: суперадмин не может ничего менять в клубе.'));
        }

        // Not saved: only describes the read-only owner view.
        $membership = (new ClubMembership)->forceFill(['club_id' => $club->getKey(), 'user_id' => $request->user()->getKey(), 'role' => 'owner']);
        $membership->setRelation('club', $club);

        $currentClub->set($club, $membership);
        $this->locales->apply($this->locales->forClub($club));
        View::share('currentClub', $club);
        View::share('currentMembership', $membership);
        View::share('availableClubs', collect());
        View::share('superadminViewing', $club);

        return true;
    }
}
