<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\MemberBalance;
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
        return $this->issueUserLoginResponse($request, $activity, $currentClub, $installationKeys, false);
    }

    public function agentUserLogin(LoginRequest $request, ActivityLogger $activity, CurrentClub $currentClub, ClubInstallationKey $installationKeys): JsonResponse
    {
        return $this->issueUserLoginResponse($request, $activity, $currentClub, $installationKeys, true);
    }

    private function issueUserLoginResponse(LoginRequest $request, ActivityLogger $activity, CurrentClub $currentClub, ClubInstallationKey $installationKeys, bool $agentContract): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->first();

        $phone = (string) $request->validated('phone');
        $failed = fn (string $reason) => $activity->recordFailedLogin('auth.api_login_failed', $phone, $reason, [
            'device' => $request->validated('device_name') ?: 'mobile',
        ]);

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            $failed('invalid_credentials');

            return response()->json(['message' => __('Неверный номер телефона или пароль.')], 401);
        }

        if ($user->isBlocked()) {
            $failed('blocked');

            return response()->json(['message' => __('Аккаунт заблокирован. Обратитесь в поддержку Zafiro.'), 'code' => 'account_blocked'], 403);
        }

        $requestedClubId = $request->validated('club_id');

        if ($request->filled('club_key')) {
            $keyClub = $installationKeys->resolve($request->validated('club_key'));

            if ($keyClub === null) {
                $failed('invalid_club_key');

                return response()->json(['message' => __('Ключ установки недействителен.')], 401);
            }

            if ($requestedClubId !== null && (int) $requestedClubId !== $keyClub->getKey()) {
                $failed('club_key_mismatch');

                return response()->json(['message' => __('Ключ установки относится к другому клубу.')], 403);
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
            $failed('no_club_access');

            return response()->json(['message' => __('У аккаунта нет доступа к выбранному клубу.')], 403);
        }

        $currentClub->set($membership->club);
        $subscription = ClubSubscription::query()->where('club_id', $membership->club_id)->first();

        if ($subscription?->isActive() !== true) {
            $failed('subscription_inactive');

            return response()->json(['message' => __('Подписка клуба не активна.')], 402);
        }

        $expiresAt = now()->addDays(30);
        $deviceName = $request->validated('device_name') ?: 'mobile';

        // A PC agent keeps one token per PC: signing in again revokes the previous ones,
        // so tokens left on a PC (or copied from it) stop working.
        if ($agentContract && $request->filled('device_name')) {
            $user->tokens()->where('name', $deviceName)->delete();
        }

        $token = $user->createToken(
            $deviceName,
            ['club:'.$membership->club_id],
            $expiresAt,
        )->plainTextToken;
        $activity->record('auth.api_login', __('Вход через API: :user', ['user' => $user->name]), $user->getKey(), $user, ['device' => $request->validated('device_name') ?: 'mobile']);

        $userPayload = $this->userData($user, $request);
        $payload = [
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $userPayload,
            'club_id' => $membership->club_id,
            'club_name' => $membership->club->name,
        ];

        if ($agentContract) {
            $payload['access_token'] = $token;
        } else {
            $payload['token'] = $token;
            $payload['access_token'] = $token;
        }

        return response()->json($payload);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userData($request->user(), $request),
        ]);
    }

    public function summary(Request $request, CurrentClub $currentClub): JsonResponse
    {
        $user = $request->user();
        $membership = $user->currentMembership()->with('balance')->firstOrFail();
        $balance = $membership->balance;

        return response()->json([
            'user_id' => $user->getKey(),
            'name' => $user->name,
            'phone' => $user->phone,
            'club_id' => $membership->club_id,
            'balance' => (float) ($balance?->balance ?? 0),
            'bonus_balance' => (float) ($balance?->bonus_balance ?? 0),
            'currency' => data_get($currentClub->settings(), 'finances.currency', 'KZT'),
        ]);
    }

    public function logout(Request $request, ActivityLogger $activity): JsonResponse
    {
        $user = $request->user();
        $activity->record('auth.api_logout', __('Выход через API: :user', ['user' => $user->name]), $user->getKey(), $user);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('Сессия завершена.')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(User $user, Request $request): array
    {
        $membership = $user->currentMembership()->first();
        $clubId = $membership?->club_id ?? app(CurrentClub::class)->id();
        $balance = $clubId !== null
            ? MemberBalance::query()->withoutGlobalScope('club')
                ->where('user_id', $user->getKey())
                ->where('club_id', $clubId)
                ->first()
            : null;
        $currency = data_get(app(CurrentClub::class)->settings(), 'finances.currency', 'KZT');

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'phone' => $user->phone,
            'club_id' => $membership?->club_id,
            'role' => $membership?->role,
            'discount' => (float) ($membership?->discount ?? 0),
            'balance' => (float) ($balance?->balance ?? 0),
            'bonus_balance' => (float) ($balance?->bonus_balance ?? 0),
            'currency' => $currency,
        ];
    }
}
