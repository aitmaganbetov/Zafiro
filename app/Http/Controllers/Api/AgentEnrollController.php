<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Services\ClubComputerRegistrar;
use App\Services\ClubComputerSessions;
use App\Services\ClubInstallationKey;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Diskless PCs lose their device token on every restart, and a PC cloned from a boot image
 * carries the token of the PC the image was made on. The agent service asks here for a token
 * of its own PC: by the club installation key and the PC's hardware UUID; issuing it revokes
 * that PC's previous token. A PC the club does not know yet (a new PC booted from the image of
 * a PC set up once by staff) is added to the club in the "unassigned" zone, so staff set up
 * the image once instead of signing in on every PC.
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
        $computer = ClubComputer::query()
            ->whereRaw('LOWER(device_id) = ?', [mb_strtolower(trim($data['device_id']))])
            ->first();

        if ($computer === null) {
            // Logged as the club owner: an activity entry needs a member of the club.
            $ownerId = ClubMembership::query()->where('role', 'owner')->orderBy('id')->value('user_id');
            try {
                $computer = $registrar->register([
                    'device_name' => trim((string) ($data['device_name'] ?? '')) ?: 'PC',
                    'device_id' => trim($data['device_id']),
                    'mac_address' => $data['mac_address'] ?? null,
                ], (int) $ownerId)['computer'];
            } catch (ValidationException $exception) {
                return response()->json(['code' => 'computer_not_added', 'message' => collect($exception->errors())->flatten()->first()], 422);
            }
        }

        // Two PCs can report the same UUID (a board vendor placeholder). The PC on record has
        // reported its MAC in heartbeats: another MAC is another PC, and it gets no token.
        $mac = $this->normalizeMac($data['mac_address'] ?? null);
        $knownMac = $this->normalizeMac($computer->mac_address);
        if ($mac !== null && $knownMac !== null && $mac !== $knownMac) {
            Log::warning('Agent enrollment refused: same device id, another MAC', [
                'club_id' => $club->getKey(),
                'computer_id' => $computer->getKey(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['code' => 'device_conflict', 'message' => __('Этот идентификатор ПК уже занят другим компьютером клуба.')], 409);
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

    private function normalizeMac(?string $mac): ?string
    {
        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));

        return strlen($hex) === 12 ? $hex : null;
    }
}
