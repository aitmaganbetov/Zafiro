<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\MemberBalance;
use App\Models\SaasPlan;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\PlatformLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
    public function login(Request $request, ActivityLogger $activity, CurrentClub $currentClub, PlatformLogger $platformLog)
    {
        $this->normalizePhone($request);
        $credentials = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+7\d{10}$/'],
            'password' => ['required'],
        ], [
            'phone.required' => __('Номер телефона обязателен.'),
            'phone.regex' => __('Введите корректный номер телефона.'),
            'password.required' => __('Пароль обязателен.'),
        ]);

        if (Auth::attempt(['phone' => $credentials['phone'], 'password' => $credentials['password']], $request->filled('remember'))) {
            $request->session()->regenerate();
            $user = $request->user();

            if ($user->isBlocked()) {
                Auth::logout();
                $activity->recordFailedLogin('auth.login_failed', $credentials['phone'], 'blocked');

                return back()->withErrors(['phone' => __('Аккаунт заблокирован. Обратитесь в поддержку Zafiro.')])->onlyInput('phone');
            }

            $membership = $user->memberships()->withoutGlobalScope('club')->with('club')->first();

            // A superadmin without a club of their own works from the platform cabinet.
            if ($membership === null && $user->isSuperadmin()) {
                $platformLog->record('superadmin.login', __('Вход суперадмина: :user', ['user' => $user->name]), $user);

                return redirect()->intended(route('superadmin.dashboard'));
            }

            if ($membership === null) {
                Auth::logout();
                $activity->recordFailedLogin('auth.login_failed', $credentials['phone'], 'no_club_access');

                return back()->withErrors(['phone' => __('У аккаунта нет доступа ни к одному клубу.')])->onlyInput('phone');
            }

            $currentClub->set($membership->club);
            $request->session()->put('current_club_id', $membership->club_id);
            $activity->record('auth.login', __('Вход в систему: :user', ['user' => $user->name]), $user->getKey(), $user);

            return redirect()->intended('/dashboard');
        }

        $activity->recordFailedLogin('auth.login_failed', $credentials['phone'], 'invalid_credentials');

        return back()->withErrors([
            'phone' => __('Неверные учетные данные.'),
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
    public function register(Request $request, ActivityLogger $activity, CurrentClub $currentClub)
    {
        $this->normalizePhone($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'club_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+7\d{10}$/', 'unique:users'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'name.required' => __('Имя обязательно.'),
            'club_name.required' => __('Название клуба обязательно.'),
            'phone.required' => __('Номер телефона обязателен.'),
            'phone.regex' => __('Введите корректный номер телефона.'),
            'phone.unique' => __('Этот номер телефона уже зарегистрирован.'),
            'password.required' => __('Пароль обязателен.'),
            'password.min' => __('Пароль должен содержать минимум 6 символов.'),
            'password.confirmed' => __('Пароли не совпадают.'),
        ]);

        [$user, $club] = DB::transaction(function () use ($validated, $currentClub, $activity): array {
            $user = User::create([
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'password' => Hash::make($validated['password']),
            ]);
            $slug = Str::slug($validated['club_name']) ?: 'club';
            $club = Club::create([
                'name' => $validated['club_name'],
                'slug' => $slug.'-'.Str::lower(Str::random(8)),
            ]);
            $club->memberships()->create(['user_id' => $user->getKey(), 'role' => 'owner']);
            $currentClub->set($club);
            MemberBalance::create(['user_id' => $user->getKey()]);
            $plan = SaasPlan::query()->firstOrCreate(
                ['slug' => 'trial'],
                ['name' => 'Пробный период', 'price_monthly' => null, 'trial_days' => 14, 'is_active' => true],
            );
            $club->subscription()->create([
                'saas_plan_id' => $plan->getKey(),
                'status' => 'trialing',
                'started_at' => now(),
                'trial_ends_at' => now()->addDays($plan->trial_days ?: 14),
            ]);
            $activity->record('auth.registered', __('Регистрация пользователя: :user', ['user' => $user->name]), $user->getKey(), $user);

            return [$user, $club];
        });

        Auth::login($user);
        $request->session()->put('current_club_id', $club->getKey());

        return redirect('/dashboard')->with('success', __('Добро пожаловать!'));
    }

    private function normalizePhone(Request $request): void
    {
        $digits = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
        if ($digits === '') {
            $request->merge(['phone' => '']);

            return;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '7')) {
            $digits = substr($digits, 1);
        }

        $request->merge(['phone' => '+7'.$digits]);
    }

    /**
     * Handle logout
     */
    public function logout(Request $request, ActivityLogger $activity)
    {
        $user = $request->user();
        $activity->record('auth.logout', __('Выход из системы: :user', ['user' => $user->name]), $user->getKey(), $user);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
