<?php

namespace App\Http\Middleware;

use App\Models\Club;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a PC agent by its device token (X-Agent-Token). The token belongs to
 * one computer, so the request can only act on that computer of that club.
 */
class AuthenticateAgentDevice
{
    public function __construct(private ClubComputerSessions $sessions, private CurrentClub $currentClub) {}

    public function handle(Request $request, Closure $next): Response
    {
        $computer = $this->sessions->findByAgentToken((string) $request->header('X-Agent-Token'));

        if ($computer === null) {
            return response()->json(['message' => __('Токен устройства недействителен.'), 'code' => 'invalid_agent_token'], 401);
        }

        $club = Club::query()->with('subscription')->findOrFail($computer->club_id);

        if ($club->subscription?->isActive() !== true) {
            return response()->json(['message' => __('Подписка клуба не активна.')], 402);
        }

        $this->currentClub->set($club);
        $this->currentClub->useUtc();
        $request->attributes->set('agent_computer', $computer);

        return $next($request);
    }
}
