<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubMembership;
use App\Services\ClubComputerRegistrar;
use App\Services\ClubComputerSessions;
use App\Services\ClubInstallationKey;
use App\Services\CurrentClub;
use App\Support\MacAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Diskless PCs lose their device token on every restart, and a PC cloned from a boot image
 * carries the token of the PC the image was made on. The agent service asks here for a token
 * of its own PC: by the club installation key and the PC's MAC (the UUID only for older agents
 * and for cards without a MAC); issuing it revokes that PC's previous token. A PC the club
 * does not know yet (a new PC booted from the image of a PC set up once by staff) is added to
 * the club in the "unassigned" zone, so staff set up the image once instead of signing in on
 * every PC.
 */
class AgentEnrollController extends Controller
{
    public function __invoke(Request $request, ClubInstallationKey $keys, ClubComputerSessions $sessions, CurrentClub $currentClub, ClubComputerRegistrar $registrar): JsonResponse
    {
        $data = $request->validate([
            'club_key' => ['required', 'string', 'size:64'],
            'device_id' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'mac_address' => ['nullable', 'string', 'max:32'],
        ]);
        $club = $keys->resolve($data['club_key']);

        if ($club === null) {
            return response()->json(['code' => 'invalid_club_key', 'message' => __('Ключ установки недействителен.')], 401);
        }

        if ($club->subscription?->isActive() !== true) {
            return response()->json(['message' => __('Подписка клуба не активна.')], 402);
        }

        $currentClub->set($club);
        // The PC is known by its MAC: one image gives every PC the same name and often the same UUID.
        $mac = MacAddress::normalize($data['mac_address'] ?? null);
        $identity = ['device_name' => trim((string) ($data['device_name'] ?? '')) ?: 'PC', 'device_id' => trim($data['device_id']), 'mac_address' => $mac];
        $computer = $registrar->find($identity);

        if ($computer === null) {
            // Logged as the club owner: an activity entry needs a member of the club.
            $ownerId = ClubMembership::query()->where('role', 'owner')->orderBy('id')->value('user_id');
            try {
                $computer = $registrar->register($identity, (int) $ownerId)['computer'];
            } catch (ValidationException $exception) {
                return response()->json(['code' => 'computer_not_added', 'message' => collect($exception->errors())->flatten()->first()], 422);
            }
        } else {
            $registrar->adoptMac($computer, $mac);
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
