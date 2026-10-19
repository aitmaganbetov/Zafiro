<?php

namespace App\Services;

use App\Models\Friendship;
use App\Models\MemberBalance;
use App\Models\TransferCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * A transfer to a friend from the mobile app is confirmed with a 6-digit code sent to the
 * account's phone in Telegram (Telegram Gateway), instead of the password the PC agent asks for.
 * The code is bound to the friend and the amount and works once. Codes cost money: limited
 * per account, and sent only for a transfer that can go through right now.
 */
class FriendTransferCodes
{
    public const CODE_MINUTES = 5;

    public const RESEND_SECONDS = 60;

    public const CODES_PER_HOUR = 5;

    public const MAX_ATTEMPTS = 5;

    public function __construct(private TelegramGateway $gateway, private Friends $friends) {}

    /** @throws ValidationException when the code cannot be sent now */
    public function send(Friendship $friendship, User $user, string $amount): void
    {
        if (! $this->gateway->configured()) {
            throw ValidationException::withMessages(['code' => __('Подтверждение кодом из Telegram пока не подключено.')]);
        }
        $cents = $this->checkedCents($friendship, $user, $amount);

        $codes = TransferCode::query()->where('user_id', $user->getKey());
        (clone $codes)->where('created_at', '<', now()->subDay())->delete();
        $last = (clone $codes)->latest('id')->first();
        if ($last !== null && $last->created_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            $seconds = self::RESEND_SECONDS - (int) $last->created_at->diffInSeconds(now());
            throw ValidationException::withMessages(['code' => __('Новый код можно запросить через :seconds с.', ['seconds' => max(1, $seconds)])]);
        }
        if ((clone $codes)->where('created_at', '>=', now()->subHour())->count() >= self::CODES_PER_HOUR) {
            throw ValidationException::withMessages(['code' => __('Слишком много запросов кода. Попробуйте через час.')]);
        }

        $code = (string) random_int(100000, 999999);
        if (! $this->gateway->sendCode((string) $user->phone, $code, self::CODE_MINUTES * 60)) {
            throw ValidationException::withMessages(['code' => __('Не удалось отправить код в Telegram. Проверьте, что на этот номер зарегистрирован Telegram, или обратитесь к администратору клуба.')]);
        }

        // Older codes stay for the hourly limit; only the newest one is checked.
        TransferCode::query()->create([
            'user_id' => $user->getKey(),
            'friendship_id' => $friendship->getKey(),
            'amount_cents' => $cents,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ]);
    }

    /**
     * The right code makes the transfer and is used up; a wrong one counts an attempt.
     *
     * @return array{balance: string, amount: string}
     *
     * @throws ValidationException
     */
    public function confirm(Friendship $friendship, User $user, string $amount, string $code): array
    {
        $cents = Friends::amountToCents($amount);
        $wrong = null;

        $result = DB::transaction(function () use ($friendship, $user, $amount, $code, $cents, &$wrong): ?array {
            // Locked: the same code sent twice at once makes one transfer.
            $row = TransferCode::query()->where('user_id', $user->getKey())->where('expires_at', '>', now())
                ->latest('id')->lockForUpdate()->first();
            if ($row === null || $row->attempts >= self::MAX_ATTEMPTS) {
                throw ValidationException::withMessages(['code' => __('Код неверный или устарел. Запросите новый код.')]);
            }
            if ($row->friendship_id !== $friendship->getKey() || $row->amount_cents !== $cents || ! Hash::check($code, $row->code_hash)) {
                $wrong = $row;

                return null;
            }

            $result = $this->friends->transfer($friendship, $user, $amount);
            TransferCode::query()->where('user_id', $user->getKey())->delete();

            return $result;
        });

        if ($result === null) {
            // Outside the transaction, so the attempt is counted even though nothing else changed.
            $wrong->increment('attempts');
            throw ValidationException::withMessages(['code' => __('Код неверный или устарел. Запросите новый код.')]);
        }

        return $result;
    }

    /** The checks of the transfer itself, before paying for a code. */
    private function checkedCents(Friendship $friendship, User $user, string $amount): int
    {
        $cents = Friends::amountToCents($amount);
        if ($cents <= 0 || $cents > Friends::MAX_TRANSFER_CENTS) {
            throw ValidationException::withMessages(['amount' => __('Сумма перевода — от 0,01 до 1 000 000.')]);
        }
        if (! $friendship->involves($user->getKey()) || $friendship->accepted_at === null) {
            throw ValidationException::withMessages(['friendship' => __('Переводить можно только друзьям.')]);
        }
        $balance = MemberBalance::query()->where('user_id', $user->getKey())->value('balance');
        if (Friends::amountToCents((string) ($balance ?? '0')) < $cents) {
            throw ValidationException::withMessages(['amount' => __('Недостаточно средств на основном счёте.')]);
        }

        return $cents;
    }
}
