<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The customer's own account in the agent profile: the name, and the password (with the
 * current one, so nobody changes it at a PC left signed in). One account serves every club.
 */
class GuestProfileController extends Controller
{
    public function name(Request $request, ActivityLogger $activity): JsonResponse
    {
        $name = trim((string) $request->validate(['name' => ['required', 'string', 'min:2', 'max:255']])['name']);
        $user = $request->user();
        $before = $user->name;
        if (mb_strlen($name) < 2) {
            throw ValidationException::withMessages(['name' => __('Имя — не короче 2 символов.')]);
        }

        if ($name !== $before) {
            $user->forceFill(['name' => $name])->save();
            $activity->record('user.profile_updated', __('Клиент изменил имя в агенте: :user', ['user' => $name]), $user->getKey(), $user, ['name' => $before.' → '.$name]);
        }

        return response()->json(['message' => __('Имя сохранено.'), 'name' => $user->name]);
    }

    public function password(Request $request, ActivityLogger $activity): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:256'],
            'password' => ['required', 'string', 'min:6', 'max:255', 'confirmed'],
        ], [
            'current_password.required' => __('Введите текущий пароль.'),
            'password.min' => __('Пароль — не короче 6 символов.'),
            'password.confirmed' => __('Пароли не совпадают.'),
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('Текущий пароль неверный.')]);
        }

        // Other devices (another PC, the phone) sign in again with the new password; this PC stays.
        DB::transaction(function () use ($user, $data): void {
            $user->forceFill(['password' => $data['password']])->save();
            $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();
        });
        $activity->record('user.password_changed', __('Клиент сменил пароль в агенте: :user', ['user' => $user->name]), $user->getKey(), $user);

        return response()->json(['message' => __('Пароль изменён. На других устройствах войдите с новым паролем.')]);
    }
}
