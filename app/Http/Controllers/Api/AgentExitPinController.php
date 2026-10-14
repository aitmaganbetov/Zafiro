<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ClubComputerSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Checks the staff PIN typed on a PC (Ctrl+Alt+P) before the agent may close.
 * Authenticated by the PC's device token and limited per PC and per club. Exits and
 * wrong PINs go to the activity log under the user signed in on that PC.
 */
class AgentExitPinController extends Controller
{
    public function __invoke(Request $request, ActivityLogger $activity, ClubComputerSessions $sessions): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'string', 'max:16']]);
        $computer = $request->attributes->get('agent_computer');
        $hash = Club::query()->whereKey($computer->club_id)->value('agent_exit_pin_hash');

        if ($hash === null) {
            return response()->json(['valid' => false, 'code' => 'pin_not_configured', 'message' => __('PIN-код не задан. Задайте его в настройках клуба в панели Zafiro.')], 409);
        }

        $valid = Hash::check($data['pin'], $hash);
        $user = $this->signedInUser($request, $computer->club_id);
        $this->record($request, $activity, $computer, $valid, $user);

        if (! $valid) {
            return response()->json(['valid' => false, 'code' => 'invalid_pin', 'message' => __('Неверный PIN-код.')], 422);
        }

        // The PC shows "maintenance" in the panel, and the agent service waits this long before
        // bringing the agent back. The PIN is shared, so the staff member is known only if they
        // are the one signed in on the PC.
        $staff = $user !== null && $this->isStaff($user, $computer->club_id) ? $user : null;

        return response()->json(['valid' => true, 'staff_minutes' => $sessions->startStaffVisit($computer, $staff, 'pin')]);
    }

    private function isStaff(User $user, int $clubId): bool
    {
        return ClubMembership::query()->withoutGlobalScope('club')
            ->where('club_id', $clubId)->where('user_id', $user->getKey())
            ->whereIn('role', ClubComputerSessions::STAFF_ROLES)->exists();
    }

    private function record(Request $request, ActivityLogger $activity, ClubComputer $computer, bool $valid, ?User $user): void
    {
        $context = ['club_id' => $computer->club_id, 'computer_id' => $computer->getKey(), 'ip' => $request->ip()];

        if ($user === null) {
            $valid ? Log::info('Agent closed with staff PIN', $context) : Log::warning('Wrong agent exit PIN', $context);

            return;
        }

        $activity->record(
            $valid ? 'agent.staff_exit' : 'agent.exit_pin_failed',
            $valid
                ? __('Агент на ПК :computer закрыт по PIN-коду сотрудника', ['computer' => $computer->displayName()])
                : __('Неверный PIN-код выхода на ПК :computer', ['computer' => $computer->displayName()]),
            $user->getKey(),
            $computer,
            ['signed_in_user' => $user->name],
        );
    }

    /** The user whose (unexpired) token the agent on this PC holds, if they belong to the club. */
    private function signedInUser(Request $request, int $clubId): ?User
    {
        $token = $request->bearerToken() ? PersonalAccessToken::findToken($request->bearerToken()) : null;

        if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast()) || ! $token->tokenable instanceof User) {
            return null;
        }

        $isMember = ClubMembership::query()->withoutGlobalScope('club')
            ->where('club_id', $clubId)->where('user_id', $token->tokenable->getKey())->exists();

        return $isMember ? $token->tokenable : null;
    }
}
