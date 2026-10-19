<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\ClubRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * A customer registers on a club PC (agent sign-in window, by the PC's device token): a new
 * Zafiro account, a customer of this club, signed in on this PC at once. One account serves every
 * club: a number already in Zafiro joins this club with the password of that account.
 */
class AgentRegisterController extends Controller
{
    public function __invoke(Request $request, ActivityLogger $activity): JsonResponse
    {
        $computer = $request->attributes->get('agent_computer');
        $digits = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            $digits = substr($digits, 1);
        }
        $request->merge(['phone' => '+7'.$digits]);

        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'min:2', 'max:255'],
                'phone' => ['required', 'regex:/^\+7\d{10}$/'],
                'password' => ['required', 'string', 'min:6', 'max:255'],
                'device_name' => ['required', 'string', 'max:120'],
            ], ['phone.regex' => __('Введите 10 цифр номера после +7.'), 'password.min' => __('Пароль — не короче 6 символов.')]);
            $existing = User::query()->where('phone', $data['phone'])->first();
            if ($existing !== null) {
                if (ClubMembership::query()->withoutGlobalScope('club')->where('club_id', $computer->club_id)->where('user_id', $existing->getKey())->exists()) {
                    throw ValidationException::withMessages(['phone' => __('Вы уже клиент этого клуба. Войдите со своим паролем.')]);
                }
                if ($existing->isBlocked()) {
                    throw ValidationException::withMessages(['phone' => __('Аккаунт заблокирован. Обратитесь в поддержку Zafiro.')]);
                }
                if (! Hash::check($data['password'], $existing->password)) {
                    $activity->recordFailedLogin('auth.api_login_failed', $data['phone'], 'join_wrong_password', ['device' => $data['device_name']]);
                    throw ValidationException::withMessages(['password' => __('Этот номер уже есть в Zafiro (вы клиент другого клуба). Введите пароль от своего аккаунта или восстановите его: «Забыли пароль?».')]);
                }
            }
        } catch (ValidationException $exception) {
            return response()->json(['message' => collect($exception->errors())->flatten()->first(), 'errors' => $exception->errors()], 422);
        }

        [$user, $token, $expiresAt] = DB::transaction(function () use ($data, $computer, $activity, $existing): array {
            // The account of another club keeps its name and password; it gets a balance here.
            $user = $existing ?? User::query()->create(['name' => trim($data['name']), 'phone' => $data['phone'], 'password' => $data['password']]);
            ClubMembership::query()->create(['user_id' => $user->getKey(), 'role' => ClubRoles::CLIENT, 'discount' => 0]);
            MemberBalance::query()->create(['user_id' => $user->getKey(), 'balance' => 0, 'bonus_balance' => 0]);
            $expiresAt = now()->addDays(30);
            $user->tokens()->where('name', $data['device_name'])->delete();
            $token = $user->createToken($data['device_name'], ['club:'.$computer->club_id], $expiresAt)->plainTextToken;
            $activity->record(
                $existing === null ? 'user.registered' : 'user.joined_club',
                $existing === null
                    ? __('Клиент зарегистрировался на ПК :computer: :user', ['computer' => $computer->displayName(), 'user' => $user->name])
                    : __('Клиент другого клуба стал клиентом этого клуба на ПК :computer: :user', ['computer' => $computer->displayName(), 'user' => $user->name]),
                $user->getKey(), $user, ['computer' => $computer->displayName()],
            );

            return [$user, $token, $expiresAt];
        });

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'club_id' => $computer->club_id,
            'user' => ['id' => $user->getKey(), 'name' => $user->name],
        ], 201);
    }
}
