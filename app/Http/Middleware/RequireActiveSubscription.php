<?php

namespace App\Http\Middleware;

use App\Models\ClubSubscription;
use App\Services\CurrentClub;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('billing.show', 'auth.logout', 'api.auth.logout', 'clubs.switch')) {
            return $next($request);
        }

        $subscription = ClubSubscription::query()
            ->where('club_id', app(CurrentClub::class)->id())
            ->first();
        $active = $subscription !== null && (
            $subscription->status === 'active'
            || ($subscription->status === 'trialing' && $subscription->trial_ends_at?->isFuture())
        );

        if ($active) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Подписка клуба не активна.'], 402);
        }

        return redirect()->route('billing.show');
    }
}