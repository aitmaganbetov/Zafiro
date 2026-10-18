<?php

namespace App\Http\Controllers;

use App\Services\PasswordRecovery;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** "Forgot password" on the site: the phone, then the code from Telegram and a new password. */
class PasswordRecoveryController extends Controller
{
    /** The number the code was asked for, between the two steps. */
    private const SESSION_KEY = 'password_recovery_phone';

    public function __construct(private PasswordRecovery $recovery) {}

    public function show(): View
    {
        return view('auth.forgot-password', ['available' => $this->recovery->available()]);
    }

    public function send(Request $request): RedirectResponse
    {
        $phone = $this->phone($request);
        $this->recovery->sendCode($phone);
        $request->session()->put(self::SESSION_KEY, $phone);

        return redirect()->route('auth.password.reset')
            ->with('status', __('Если номер зарегистрирован в Zafiro, код придёт в Telegram на этот номер. Код действует :minutes мин.', ['minutes' => PasswordRecovery::CODE_MINUTES]));
    }

    public function edit(Request $request): View|RedirectResponse
    {
        $phone = $request->session()->get(self::SESSION_KEY);
        if (! is_string($phone)) {
            return redirect()->route('auth.password.forgot');
        }

        return view('auth.reset-password', ['phone' => $phone]);
    }

    public function update(Request $request): RedirectResponse
    {
        $phone = $request->session()->get(self::SESSION_KEY);
        if (! is_string($phone)) {
            return redirect()->route('auth.password.forgot');
        }
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
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('auth.show-login')->with('status', __('Пароль изменён. Войдите с новым паролем.'));
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
