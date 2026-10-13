<?php

namespace App\Http\Middleware;

use App\Models\ClubMembership;
use App\Services\CurrentClub;
use App\Services\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentClub
{
    public function __construct(private LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $currentClub = app(CurrentClub::class);
        $memberships = ClubMembership::query()
            ->withoutGlobalScope('club')
            ->with('club.subscription')
            ->where('user_id', $request->user()->getKey())
            ->get();

        abort_if($memberships->isEmpty(), 403, __('У аккаунта нет доступа ни к одному клубу.'));

        if ($request->is('api/*')) {
            $clubHeader = $request->header('X-Club-Id');
            $token = $request->user()->currentAccessToken();
            $clubAbility = collect($token?->abilities ?? [])
                ->first(fn (string $ability): bool => str_starts_with($ability, 'club:'));
            $tokenClubId = $clubAbility ? (int) substr($clubAbility, 5) : null;
            if ($clubHeader && $tokenClubId !== null) {
                abort_unless((int) $clubHeader === $tokenClubId, 403, __('API-токен привязан к другому клубу.'));
            }
            $requestedClubId = $clubHeader ?: ($clubAbility ? substr($clubAbility, 5) : null);
        } else {
            $requestedClubId = $request->session()->get('current_club_id');
        }

        $membership = $requestedClubId
            ? $memberships->firstWhere('club_id', (int) $requestedClubId)
            : $memberships->first();

        abort_if($membership === null, 403, __('Нет доступа к выбранному клубу.'));

        $currentClub->set($membership->club, $membership);
        $this->locales->apply($this->locales->forClub($membership->club));

        if (! $request->is('api/*')) {
            $request->session()->put('current_club_id', $membership->club_id);
            View::share('currentClub', $membership->club);
            View::share('currentMembership', $membership);
            View::share('availableClubs', $memberships);
        }

        return $next($request);
    }
}
