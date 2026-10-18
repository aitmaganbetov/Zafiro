<?php

namespace App\Services;

use App\Models\PasswordRecoveryCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot password" on the site and in the PC agent: a 6-digit code goes to the account's phone
 * in Telegram (Telegram Gateway), the code sets a new password. Codes cost money, so they are
 * limited per account; an unknown number gets the same answer as a known one.
 */
class PasswordRecovery
{
    public const CODE_MINUTES = 5;

    public const RESEND_SECONDS = 60;

    public const CODES_PER_HOUR = 5;

    public const MAX_ATTEMPTS = 5;

    public function __construct(private TelegramGateway $gateway, private PlatformLogger $platformLog) {}

    public function available(): bool
    {
        return $this->gateway->configured();
    }

    /** @throws ValidationException (phone) when the code cannot be sent now */
    public function sendCode(string $phone): void
    {
        if (! $this->available()) {
            throw ValidationException::withMessages(['phone' => __('Восстановление пароля через Telegram пока не подключено. Обратитесь к администратору клуба.')]);
        }

        $user = $this->account($phone);
        if ($user === null) {
            return;
        }

        $codes = PasswordRecoveryCode::query()->where('user_id', $user->getKey());
        (clone $codes)->where('created_at', '<', now()->subDay())->delete();
        $last = (clone $codes)->latest('id')->first();
        if ($last !== null && $last->created_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            $seconds = self::RESEND_SECONDS - (int) $last->created_at->diffInSeconds(now());
            throw ValidationException::withMessages(['phone' => __('Новый код можно запросить через :seconds с.', ['seconds' => max(1, $seconds)])]);
        }
        if ((clone $codes)->where('created_at', '>=', now()->subHour())->count() >= self::CODES_PER_HOUR) {
            throw ValidationException::withMessages(['phone' => __('Слишком много запросов кода. Попробуйте через час.')]);
        }

        $code = (string) random_int(100000, 999999);
        if (! $this->gateway->sendCode($user->phone, $code, self::CODE_MINUTES * 60)) {
            throw ValidationException::withMessages(['phone' => __('Не удалось отправить код в Telegram. Проверьте, что на этот номер зарегистрирован Telegram, или обратитесь к администратору клуба.')]);
        }

        // Older codes stay for the hourly limit; only the newest one is checked.
        PasswordRecoveryCode::query()->create([
            'user_id' => $user->getKey(),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ]);
        $this->platformLog->record('user.password_code_sent', __('Код восстановления пароля отправлен в Telegram: :user', ['user' => $user->name]), $user);
    }

    /**
     * A right code sets the new password and signs the account out everywhere.
     *
     * @throws ValidationException (code) when the code is wrong, expired or tried too often
     */
    public function reset(string $phone, string $code, string $password): User
    {
        $user = $this->account($phone);
        $recovery = $user === null ? null : PasswordRecoveryCode::query()->where('user_id', $user->getKey())
            ->where('expires_at', '>', now())->latest('id')->first();

        if ($recovery === null || $recovery->attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => __('Код неверный или устарел. Запросите новый код.')]);
        }
        if (! Hash::check($code, $recovery->code_hash)) {
            $recovery->increment('attempts');
            throw ValidationException::withMessages(['code' => __('Код неверный или устарел. Запросите новый код.')]);
        }

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
            $sessions = config('session.table', 'sessions');
            if (Schema::hasTable($sessions)) {
                DB::table($sessions)->where('user_id', $user->getKey())->delete();
            }
            PasswordRecoveryCode::query()->where('user_id', $user->getKey())->delete();
        });
        $this->platformLog->record('user.password_recovered', __('Пароль восстановлен через Telegram: :user', ['user' => $user->name]), $user);

        return $user;
    }

    private function account(string $phone): ?User
    {
        $user = User::query()->where('phone', $phone)->first();

        return $user === null || $user->isBlocked() ? null : $user;
    }
}
