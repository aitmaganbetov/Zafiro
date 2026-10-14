<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubComputer;
use App\Services\ClubComputerSessions;
use App\Services\ClubInstallationKey;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Diskless PCs lose their device token on every restart, and a PC cloned from a boot image
 * carries the token of the PC the image was made on. The agent service asks here for a token
 * of its own PC: by the club installation key and the PC's hardware UUID. Only a PC that
 * staff already registered in the club gets one; issuing it revokes that PC's previous token.
 */
class AgentEnrollController extends Controller
{
    public function __invoke(Request $request, ClubInstallationKey $keys, ClubComputerSessions $sessions, CurrentClub $currentClub): JsonResponse
    {
        $data = $request->validate([
            'club_key' => ['required', 'string', 'size:64'],
            'device_id' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);
        $club = $keys->resolve($data['club_key']);

        if ($club === null) {
            return response()->json(['code' => 'invalid_club_key', 'message' => __('Ключ установки недействителен.')], 401);
        }

        if ($club->subscription?->isActive() !== true) {
            return response()->json(['message' => __('Подписка клуба не активна.')], 402);
        }

        $currentClub->set($club);
        $computer = ClubComputer::query()
            ->whereRaw('LOWER(device_id) = ?', [mb_strtolower(trim($data['device_id']))])
            ->first();

        if ($computer === null) {
            return response()->json(['code' => 'computer_not_found', 'message' => __('ПК не зарегистрирован в клубе: сотрудник должен один раз войти на нём в агенте.')], 404);
        }

        $token = $sessions->issueAgentToken($computer);
        // No user acts here, so it goes to the system log like other agent events without a user.
        Log::info('Agent enrolled with the club key', [
            'club_id' => $club->getKey(),
            'computer_id' => $computer->getKey(),
            'device_name' => $data['device_name'] ?? null,
            'ip' => $request->ip(),
        ]);

        return response()->json(['agent_token' => $token, 'computer_id' => $computer->getKey(), 'number' => (string) $computer->number]);
    }
}
