<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request, ActivityLogger $activity): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Неверный номер телефона или пароль.'], 401);
        }

        $token = $user->createToken(
            $request->validated('device_name') ?: 'mobile',
            ['*'],
            now()->addDays(30),
        )->plainTextToken;
        $activity->record('auth.api_login', "Вход через API: {$user->name}", $user->getKey(), $user, ['device' => $request->validated('device_name') ?: 'mobile']);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
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