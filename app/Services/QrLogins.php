<?php

namespace App\Services;

use App\Models\AgentQrLogin;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in on a PC from the phone. The PC (by its device token) gets a one-time code for
 * a QR code; the customer opens it on the site, signs in there and confirms; the PC then
 * receives the customer's token, once. Only the hash of the code is stored.
 */
class QrLogins
{
    public const TOKEN_DAYS = 30;

    public function __construct(private ActivityLogger $activity) {}

    /** @return array{0: AgentQrLogin, 1: string} the login and its code (shown only in the QR) */
    public function create(ClubComputer $computer): array
    {
        return DB::transaction(function () use ($computer): array {
            // One open code per PC: a new QR code replaces the previous one.
            AgentQrLogin::query()->where('club_computer_id', $computer->getKey())->delete();
            $code = Str::random(40);
            $login = AgentQrLogin::query()->create([
                'club_computer_id' => $computer->getKey(),
                'code_hash' => AgentQrLogin::hashCode($code),
                'expires_at' => now()->addMinutes(AgentQrLogin::LIFETIME_MINUTES),
            ]);

            return [$login, $code];
        });
    }

    /**
     * The PC asks whether the customer confirmed. Approved: the customer's token, once.
     *
     * @return array<string, mixed>
     */
    public function poll(ClubComputer $computer, string $id, string $deviceName): array
    {
        return DB::transaction(function () use ($computer, $id, $deviceName): array {
            $login = AgentQrLogin::query()->whereKey($id)->where('club_computer_id', $computer->getKey())->lockForUpdate()->first();
            if ($login === null || ! $login->isOpen()) {
                return ['status' => 'expired'];
            }
            if ($login->approved_user_id === null) {
                return ['status' => 'pending'];
            }

            $user = User::query()->findOrFail($login->approved_user_id);
            $login->update(['consumed_at' => now()]);
            if ($user->isBlocked() || ! ClubMembership::query()->where('user_id', $user->getKey())->exists()) {
                return ['status' => 'expired'];
            }

            // As with the password sign-in: one token per PC, the previous one stops working.
            $user->tokens()->where('name', $deviceName)->delete();
            $expiresAt = now()->addDays(self::TOKEN_DAYS);
            $token = $user->createToken($deviceName, ['club:'.$computer->club_id], $expiresAt)->plainTextToken;
            $this->activity->record('auth.qr_login', __('Вход по QR-коду на ПК :computer: :user', ['computer' => $computer->displayName(), 'user' => $user->name]), $user->getKey(), $user, [
                'computer' => $computer->displayName(),
            ]);

            return [
                'status' => 'approved',
                'token_type' => 'Bearer',
                'access_token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
                'club_id' => $computer->club_id,
                'club_name' => $computer->club?->name,
                'user' => ['id' => $user->getKey(), 'name' => $user->name],
            ];
        });
    }

    /** The open login of this code, in any club (the phone page knows only the code). */
    public function findOpen(string $code): ?AgentQrLogin
    {
        $login = AgentQrLogin::query()->withoutGlobalScope('club')
            ->with(['computer' => fn ($query) => $query->withoutGlobalScope('club'), 'club'])
            ->where('code_hash', AgentQrLogin::hashCode($code))
            ->first();

        return $login !== null && $login->isOpen() && $login->approved_user_id === null ? $login : null;
    }

    /** The signed-in customer confirms the sign-in on the PC. */
    public function approve(AgentQrLogin $login, User $user): void
    {
        if ($user->isBlocked()) {
            throw ValidationException::withMessages(['code' => __('Аккаунт заблокирован. Обратитесь в поддержку Zafiro.')]);
        }
        $role = ClubMembership::query()->withoutGlobalScope('club')
            ->where('club_id', $login->club_id)->where('user_id', $user->getKey())->value('role');
        if ($role === null) {
            throw ValidationException::withMessages(['code' => __('Ваш аккаунт не состоит в этом клубе.')]);
        }
        // One PC at a time, as with the password sign-in.
        $elsewhere = app(AgentSignIns::class)->elsewhere($user, $login->computer?->device_name, $role);
        if ($elsewhere !== null) {
            throw ValidationException::withMessages(['code' => AgentSignIns::message($elsewhere)]);
        }
        if ($login->club?->subscription?->isActive() !== true) {
            throw ValidationException::withMessages(['code' => __('Подписка клуба не активна.')]);
        }

        $updated = AgentQrLogin::query()->withoutGlobalScope('club')->whereKey($login->getKey())
            ->whereNull('approved_user_id')->whereNull('consumed_at')->where('expires_at', '>', now())
            ->update(['approved_user_id' => $user->getKey(), 'approved_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['code' => __('QR-код устарел. Обновите его на ПК и отсканируйте снова.')]);
        }
    }
}
