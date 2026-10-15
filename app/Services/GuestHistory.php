<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The customer's own log in the PC agent: purchases, top-ups at the cash register, transfers
 * between friends, seat changes and sign-ins on PCs in the current club, newest first.
 */
class GuestHistory
{
    public const PAGE = 30;

    /** Done by the customer. */
    private const OWN_EVENTS = ['tariff.purchased', 'time.purchased', 'balance.transferred', 'session.transferred', 'auth.api_login', 'auth.qr_login'];

    /** Done to the customer's account by someone else (staff, a friend). */
    private const RECEIVED_EVENTS = ['user.balance_topped_up', 'balance.transferred'];

    /** @return array{data: list<array<string, mixed>>, next_before: ?int} */
    public function page(User $user, ?int $before = null): array
    {
        $userId = $user->getKey();
        $columns = ['id', 'user_id', 'event', 'subject_type', 'subject_id', 'details', 'created_at'];
        // Two indexed queries (author; subject) instead of one OR across both.
        $own = ActivityLog::query()->where('user_id', $userId)->whereIn('event', self::OWN_EVENTS)
            ->when($before, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit(self::PAGE + 1)->get($columns);
        $received = ActivityLog::query()
            ->where('subject_type', $user->getMorphClass())->where('subject_id', $userId)
            ->where('user_id', '!=', $userId)
            ->whereIn('event', self::RECEIVED_EVENTS)
            ->when($before, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit(self::PAGE + 1)->get($columns);

        $logs = $own->concat($received)->unique('id')->sortByDesc('id')->values();
        $page = $logs->take(self::PAGE);
        $names = $this->names($page);

        return [
            'data' => $page->map(fn (ActivityLog $log): array => $this->entry($log, $userId, $names))->values()->all(),
            'next_before' => $logs->count() > self::PAGE ? $page->last()->id : null,
        ];
    }

    /** @return array<string, mixed> */
    private function entry(ActivityLog $log, int $userId, array $names): array
    {
        $details = is_array($log->details) ? $log->details : [];
        $entry = ['id' => $log->id, 'at' => $log->created_at?->utc()->toIso8601String(), 'kind' => $log->event, 'title' => '', 'note' => null, 'amount' => null, 'bonus_amount' => null];

        switch ($log->event) {
            case 'tariff.purchased':
                $entry['kind'] = 'purchase';
                $entry['title'] = __('Покупка тарифа «:tariff»', ['tariff' => $names['tariffs'][$log->subject_id] ?? '—']);
                $entry['note'] = isset($details['duration_minutes']) ? trans_choice(':count минута|:count минуты|:count минут', (int) $details['duration_minutes']) : null;
                $entry['amount'] = self::negative($details['main_balance_amount'] ?? $details['amount'] ?? null);
                $entry['bonus_amount'] = self::negative($details['bonus_balance_amount'] ?? null);
                break;
            case 'time.purchased':
                $entry['kind'] = 'purchase';
                $entry['title'] = __('Поминутная оплата: :count мин.', ['count' => (int) ($details['duration_minutes'] ?? 0)]);
                $entry['note'] = isset($details['price_per_hour']) ? __(':price за час', ['price' => $details['price_per_hour']]) : null;
                $entry['amount'] = self::negative($details['main_balance_amount'] ?? $details['amount'] ?? null);
                $entry['bonus_amount'] = self::negative($details['bonus_balance_amount'] ?? null);
                break;
            case 'user.balance_topped_up':
                $entry['kind'] = 'top_up';
                $entry['title'] = __('Пополнение на кассе');
                $entry['note'] = $details['payment_method'] ?? null;
                $bonus = in_array($details['balance_type'] ?? null, array_map(fn (string $locale): string => __('бонусный баланс', [], $locale), ['ru', 'kk', 'en']), true);
                $entry[$bonus ? 'bonus_amount' : 'amount'] = self::positive($details['amount'] ?? null);
                break;
            case 'balance.transferred':
                $outgoing = (int) $log->user_id === $userId;
                $entry['kind'] = $outgoing ? 'transfer_out' : 'transfer_in';
                $entry['title'] = $outgoing
                    ? __('Перевод другу :name', ['name' => $names['users'][$details['to_user_id'] ?? 0] ?? '—'])
                    : __('Перевод от друга :name', ['name' => $names['users'][$log->user_id] ?? '—']);
                $entry['amount'] = $outgoing ? self::negative($details['amount'] ?? null) : self::positive($details['amount'] ?? null);
                break;
            case 'session.transferred':
                $entry['kind'] = 'seat';
                $entry['title'] = __('Пересадка на ПК :computer', ['computer' => $details['to'] ?? '—']);
                $entry['note'] = isset($details['from']) ? __('С ПК :computer', ['computer' => $details['from']]) : null;
                break;
            default:
                $entry['kind'] = 'login';
                $entry['title'] = $log->event === 'auth.qr_login' ? __('Вход на ПК по QR-коду') : __('Вход в аккаунт');
                $entry['note'] = $details['computer'] ?? $details['device'] ?? null;
        }

        return $entry;
    }

    /**
     * Names for one page: tariffs bought and friends money moved to or from.
     *
     * @param  Collection<int, ActivityLog>  $logs
     * @return array{tariffs: array<int, string>, users: array<int, string>}
     */
    private function names(Collection $logs): array
    {
        $tariffIds = $logs->where('event', 'tariff.purchased')->pluck('subject_id')->filter()->unique()->all();
        $userIds = $logs->where('event', 'balance.transferred')
            ->flatMap(fn (ActivityLog $log): array => [$log->user_id, (int) ($log->details['to_user_id'] ?? 0)])
            ->filter()->unique()->all();

        return [
            'tariffs' => $tariffIds === [] ? [] : Tariff::query()->whereKey($tariffIds)->pluck('name', 'id')->all(),
            'users' => $userIds === [] ? [] : User::query()->whereKey($userIds)->pluck('name', 'id')->all(),
        ];
    }

    private static function negative(mixed $amount): ?string
    {
        return is_numeric($amount) && (float) $amount > 0 ? '-'.number_format((float) $amount, 2, '.', '') : null;
    }

    private static function positive(mixed $amount): ?string
    {
        return is_numeric($amount) && (float) $amount > 0 ? number_format((float) $amount, 2, '.', '') : null;
    }
}
