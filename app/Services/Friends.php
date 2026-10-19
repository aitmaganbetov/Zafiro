<?php

namespace App\Services;

use App\Models\Friendship;
use App\Models\MemberBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Friends among the customers of the current club and main-balance transfers between them. */
class Friends
{
    public const MAX_TRANSFER_CENTS = 100_000_000; // 1 000 000 per transfer

    public function __construct(private ActivityLogger $activity, private CurrentClub $currentClub) {}

    /**
     * @return array{friends: list<array<string, mixed>>, incoming: list<array<string, mixed>>, outgoing: list<array<string, mixed>>}
     */
    public function overview(User $user): array
    {
        $userId = $user->getKey();
        $rows = Friendship::query()->involving($userId)
            ->with(['requester:id,name,phone', 'addressee:id,name,phone'])
            ->orderByDesc('accepted_at')->orderByDesc('id')
            ->limit(300)
            ->get();
        $entry = function (Friendship $friendship) use ($userId): array {
            $other = $friendship->requester_id === $userId ? $friendship->addressee : $friendship->requester;

            return ['id' => $friendship->id, 'name' => $other?->name, 'phone' => self::maskPhone($other?->phone)];
        };

        return [
            'friends' => $rows->whereNotNull('accepted_at')->map($entry)->values()->all(),
            'incoming' => $rows->whereNull('accepted_at')->where('addressee_id', $userId)->map($entry)->values()->all(),
            'outgoing' => $rows->whereNull('accepted_at')->where('requester_id', $userId)->map($entry)->values()->all(),
        ];
    }

    /** A request to the club customer with this phone; a request from them is accepted instead. */
    public function request(User $user, string $phone): Friendship
    {
        $friend = User::query()
            ->where('phone', $phone)
            ->whereHas('memberships', fn ($memberships) => $memberships->where('club_id', $this->currentClub->id()))
            ->first(['id', 'name']);
        if ($friend === null) {
            throw ValidationException::withMessages(['phone' => __('Клиент с таким номером в клубе не найден.')]);
        }
        if ($friend->getKey() === $user->getKey()) {
            throw ValidationException::withMessages(['phone' => __('Нельзя добавить в друзья самого себя.')]);
        }

        return DB::transaction(function () use ($user, $friend): Friendship {
            $existing = Friendship::query()->lockForUpdate()
                ->where(fn ($query) => $query
                    ->where(fn ($pair) => $pair->where('requester_id', $user->getKey())->where('addressee_id', $friend->getKey()))
                    ->orWhere(fn ($pair) => $pair->where('requester_id', $friend->getKey())->where('addressee_id', $user->getKey())))
                ->first();

            if ($existing?->accepted_at !== null) {
                throw ValidationException::withMessages(['phone' => __('Этот клиент уже у вас в друзьях.')]);
            }
            if ($existing !== null && $existing->requester_id === $user->getKey()) {
                throw ValidationException::withMessages(['phone' => __('Заявка этому клиенту уже отправлена.')]);
            }
            if ($existing !== null) {
                return $this->acceptLocked($existing, $user);
            }

            $friendship = Friendship::query()->create(['requester_id' => $user->getKey(), 'addressee_id' => $friend->getKey()]);
            $this->activity->record('friend.requested', __(':user отправил заявку в друзья :friend', ['user' => $user->name, 'friend' => $friend->name]), $user->getKey(), $friendship);

            return $friendship;
        });
    }

    public function accept(Friendship $friendship, User $user): Friendship
    {
        return DB::transaction(function () use ($friendship, $user): Friendship {
            $friendship = Friendship::query()->lockForUpdate()->findOrFail($friendship->getKey());
            if ($friendship->addressee_id !== $user->getKey() || $friendship->accepted_at !== null) {
                throw ValidationException::withMessages(['friendship' => __('Заявка не найдена.')]);
            }

            return $this->acceptLocked($friendship, $user);
        });
    }

    /** Declines a request, cancels one's own request or removes a friend. */
    public function remove(Friendship $friendship, User $user): void
    {
        if (! $friendship->involves($user->getKey())) {
            throw ValidationException::withMessages(['friendship' => __('Заявка не найдена.')]);
        }

        DB::transaction(function () use ($friendship, $user): void {
            $friendship->delete();
            $this->activity->record('friend.removed', __(':user удалил дружбу или заявку', ['user' => $user->name]), $user->getKey(), null, [
                'friendship_id' => $friendship->id,
                'accepted' => $friendship->accepted_at !== null,
            ]);
        });
    }

    /**
     * Moves money from the user's main balance to a friend's main balance (bonuses are not transferred).
     *
     * @return array{balance: string, amount: string}
     */
    public function transfer(Friendship $friendship, User $user, string $amount): array
    {
        $cents = self::amountToCents($amount);
        if ($cents <= 0 || $cents > self::MAX_TRANSFER_CENTS) {
            throw ValidationException::withMessages(['amount' => __('Сумма перевода — от 0,01 до 1 000 000.')]);
        }

        return DB::transaction(function () use ($friendship, $user, $cents): array {
            $friendship = Friendship::query()->lockForUpdate()->findOrFail($friendship->getKey());
            if (! $friendship->involves($user->getKey()) || $friendship->accepted_at === null) {
                throw ValidationException::withMessages(['friendship' => __('Переводить можно только друзьям.')]);
            }

            $friendId = $friendship->otherThan($user->getKey());
            // Both balances are locked in one order, so two opposite transfers cannot deadlock.
            $balances = MemberBalance::query()->whereIn('user_id', [$user->getKey(), $friendId])->orderBy('user_id')->lockForUpdate()->get()->keyBy('user_id');
            $from = $balances->get($user->getKey());
            $to = $balances->get($friendId);
            if ($from === null || $to === null) {
                throw ValidationException::withMessages(['friendship' => __('Переводить можно только друзьям.')]);
            }

            $fromCents = self::storedCents((string) $from->balance);
            if ($fromCents < $cents) {
                throw ValidationException::withMessages(['amount' => __('Недостаточно средств на основном счёте.')]);
            }

            $from->update(['balance' => self::formatCents($fromCents - $cents)]);
            $to->update(['balance' => self::formatCents(self::storedCents((string) $to->balance) + $cents)]);
            $friend = User::query()->findOrFail($friendId, ['id', 'name']);
            $this->activity->record('balance.transferred', __(':user перевёл другу :friend :amount', [
                'user' => $user->name,
                'friend' => $friend->name,
                'amount' => self::formatCents($cents),
            ]), $user->getKey(), $friend, [
                // The friend is the subject: the transfer shows in both customers' history.
                'amount' => self::formatCents($cents),
                'to_user_id' => $friendId,
                'friendship_id' => $friendship->id,
            ]);

            return ['balance' => self::formatCents($fromCents - $cents), 'amount' => self::formatCents($cents)];
        });
    }

    /** Friends see each other's name and only the last digits of the phone. */
    public static function maskPhone(?string $phone): ?string
    {
        if ($phone === null || ! preg_match('/^\+7(\d{3})\d{5}(\d{2})$/', $phone, $parts)) {
            return $phone === null ? null : '•••';
        }

        return "+7 {$parts[1]} ••• •• {$parts[2]}";
    }

    private function acceptLocked(Friendship $friendship, User $user): Friendship
    {
        $friendship->update(['accepted_at' => now()]);
        $this->activity->record('friend.accepted', __(':user принял заявку в друзья', ['user' => $user->name]), $user->getKey(), $friendship);

        return $friendship;
    }

    public static function amountToCents(string $amount): int
    {
        $amount = str_replace(',', '.', trim($amount));
        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount)) {
            return 0;
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /** A stored decimal(…, 2) balance, possibly negative. */
    private static function storedCents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function formatCents(int $cents): string
    {
        return ($cents < 0 ? '-' : '').sprintf('%d.%02d', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
