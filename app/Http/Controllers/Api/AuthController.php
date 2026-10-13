<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ClubInstallationKey;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request, ActivityLogger $activity, CurrentClub $currentClub, ClubInstallationKey $installationKeys): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Неверный номер телефона или пароль.'], 401);
        }

        $requestedClubId = $request->validated('club_id');

        if ($request->filled('club_key')) {
            $keyClub = $installationKeys->resolve($request->validated('club_key'));

            if ($keyClub === null) {
                return response()->json(['message' => 'Ключ установки недействителен.'], 401);
            }

            if ($requestedClubId !== null && (int) $requestedClubId !== $keyClub->getKey()) {
                return response()->json(['message' => 'Ключ установки относится к другому клубу.'], 403);
            }

            $requestedClubId = $keyClub->getKey();
        }

        $membership = ClubMembership::query()
            ->withoutGlobalScope('club')
            ->with('club')
            ->where('user_id', $user->getKey())
            ->when($requestedClubId, fn ($query, $clubId) => $query->where('club_id', $clubId))
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'У аккаунта нет доступа к выбранному клубу.'], 403);
        }

        $currentClub->set($membership->club);
        $subscription = ClubSubscription::query()->where('club_id', $membership->club_id)->first();

        if ($subscription?->isActive() !== true) {
            return response()->json(['message' => 'Подписка клуба не активна.'], 402);
        }

        $token = $user->createToken(
            $request->validated('device_name') ?: 'mobile',
            ['club:'.$membership->club_id],
            now()->addDays(30),
        )->plainTextToken;
        $activity->record('auth.api_login', "Вход через API: {$user->name}", $user->getKey(), $user, ['device' => $request->validated('device_name') ?: 'mobile']);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'club_id' => $membership->club_id,
            'club_name' => $membership->club->name,
            'user' => $this->userData($user, $request),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userData($request->user(), $request),
        ]);
    }

    public function logout(Request $request, ActivityLogger $activity): JsonResponse
    {
        $user = $request->user();
        $activity->record('auth.api_logout', "Выход через API: {$user->name}", $user->getKey(), $user);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Сессия завершена.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(User $user, Request $request): array
    {
        return (new UserResource($user))->resolve($request);
    }
}
