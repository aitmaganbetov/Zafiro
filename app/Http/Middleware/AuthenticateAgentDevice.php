<?php

namespace App\Http\Middleware;

use App\Models\Club;
use App\Models\ClubComputer;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use App\Services\LocaleResolver;
use App\Support\MacAddress;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a PC agent by its device token (X-Agent-Token). The token belongs to
 * one computer, so the request can only act on that computer of that club.
 */
class AuthenticateAgentDevice
{
    public function __construct(private ClubComputerSessions $sessions, private CurrentClub $currentClub, private LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $computer = $this->sessions->findByAgentToken((string) $request->header('X-Agent-Token'));

        if ($computer === null) {
            return response()->json(['message' => __('Токен устройства недействителен.'), 'code' => 'invalid_agent_token'], 401);
        }

        // A PC cloned from a boot image carries the token of the PC the image was made on.
        // The token is kept: it still belongs to that PC; the clone asks for its own (agent/enroll).
        // Agents identify the PC by its MAC (MAC-AABBCCDDEEFF); a card registered by an older
        // agent keeps its UUID in device_id and is matched by its MAC too.
        $device = $request->header('X-Agent-Device');
        if (is_string($device) && trim($device) !== '' && ! $this->samePc(trim($device), $computer)) {
            return response()->json(['message' => __('Токен устройства принадлежит другому ПК.'), 'code' => 'device_mismatch'], 401);
        }

        $club = Club::query()->with('subscription')->findOrFail($computer->club_id);

        if ($club->subscription?->isActive() !== true) {
            return response()->json(['message' => __('Подписка клуба не активна.')], 402);
        }

        $this->currentClub->set($club);
        $this->currentClub->useUtc();
        // SetLocale ran before the club was known: answer in the PC's language, else the club's.
        $this->locales->apply($this->locales->forAgentRequest($request, $club));
        $request->attributes->set('agent_computer', $computer);

        return $next($request);
    }

    private function samePc(string $device, ClubComputer $computer): bool
    {
        if ($computer->device_id === null && $computer->mac_address === null) {
            return true;
        }

        return ($computer->device_id !== null && mb_strtolower($device) === mb_strtolower($computer->device_id))
            || ($computer->mac_address !== null && strcasecmp($device, MacAddress::deviceId($computer->mac_address)) === 0);
    }
}
