<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Show the login form
     */
    public function showLogin()
    {
        return view('auth.login');
    }

    /**
     * Handle login
     */
    public function login(Request $request, ActivityLogger $activity)
    {
        $credentials = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/'],
            'password' => ['required'],
        ], [
            'phone.required' => 'Номер телефона обязателен.',
            'phone.regex' => 'Введите корректный номер телефона.',
            'password.required' => 'Пароль обязателен.',
        ]);

        if (Auth::attempt(['phone' => $credentials['phone'], 'password' => $credentials['password']], $request->filled('remember'))) {
            $request->session()->regenerate();
            $user = $request->user();
            $activity->record('auth.login', "Вход в систему: {$user->name}", $user->getKey(), $user);

            return redirect()->intended('/dashboard');
        }

        return back()->withErrors([
            'phone' => 'Неверные учетные данные.',
        ])->onlyInput('phone');
    }

    /**
     * Show the registration form
     */
    public function showRegister()
    {
        return view('auth.register');
    }

    /**
     * Handle registration
     */
    public function register(Request $request, ActivityLogger $activity)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/', 'unique:users'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'name.required' => 'Имя обязательно.',
            'phone.required' => 'Номер телефона обязателен.',
            'phone.regex' => 'Введите корректный номер телефона.',
            'phone.unique' => 'Этот номер телефона уже зарегистрирован.',
            'password.required' => 'Пароль обязателен.',
            'password.min' => 'Пароль должен содержать минимум 6 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
        ]);

        Auth::login($user);
        $activity->record('auth.registered', "Регистрация пользователя: {$user->name}", $user->getKey(), $user);

        return redirect('/dashboard')->with('success', 'Добро пожаловать!');
    }

    /**
     * Handle logout
     */
    public function logout(Request $request, ActivityLogger $activity)
    {
        $user = $request->user();
        $activity->record('auth.logout', "Выход из системы: {$user->name}", $user->getKey(), $user);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
