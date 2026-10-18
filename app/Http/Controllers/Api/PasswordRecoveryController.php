<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PasswordRecovery;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Forgot password" in the PC agent: a code to the phone in Telegram, then the code and a new password. */
class PasswordRecoveryController extends Controller
{
    public function __construct(private PasswordRecovery $recovery) {}

    public function code(Request $request): JsonResponse
    {
        $this->recovery->sendCode($this->phone($request));

        return response()->json([
            'message' => __('Если номер зарегистрирован в Zafiro, код придёт в Telegram на этот номер. Код действует :minutes мин.', ['minutes' => PasswordRecovery::CODE_MINUTES]),
            'code_minutes' => PasswordRecovery::CODE_MINUTES,
            'resend_seconds' => PasswordRecovery::RESEND_SECONDS,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'code.required' => __('Введите код из Telegram.'),
            'code.regex' => __('Код — 6 цифр из сообщения в Telegram.'),
            'password.required' => __('Пароль обязателен.'),
            'password.min' => __('Пароль должен содержать минимум 6 символов.'),
            'password.confirmed' => __('Пароли не совпадают.'),
        ]);

        $this->recovery->reset($phone, $data['code'], $data['password']);

        return response()->json(['message' => __('Пароль изменён. Войдите с новым паролем.')]);
    }

    private function phone(Request $request): string
    {
        $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);

        return $request->validate(
            ['phone' => ['required', 'string', 'regex:/^\+7\d{10}$/']],
            ['phone.required' => __('Номер телефона обязателен.'), 'phone.regex' => __('Введите корректный номер телефона.')],
        )['phone'];
    }
}
