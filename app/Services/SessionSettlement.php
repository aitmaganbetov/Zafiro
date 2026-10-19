<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Models\MemberBalance;
use App\Models\SessionPurchase;
use App\Models\TimePackage;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A customer session ended before its time: the unused time is taken from its parts from the last
 * one back. Minutes bought by the minute go back as money to the balances they were paid from;
 * a tariff with a usage limit is kept as a package until it expires; other tariffs are not refunded.
 */
class SessionSettlement
{
    public function __construct(private ActivityLogger $activity, private LoyaltyProgram $loyalty) {}

    /**
     * Call inside the transaction that ends the session, with the session's values before the end.
     *
     * @return array{refund_main: int, refund_bonus: int, kept_minutes: int} cents and minutes
     */
    public function settle(ClubComputer $computer, string $sessionId, int $userId, ?Carbon $endsAt, int $actorId): array
    {
        $result = ['refund_main' => 0, 'refund_bonus' => 0, 'kept_minutes' => 0];
        $parts = SessionPurchase::query()->where('session_id', $sessionId)->orderByDesc('id')->get();
        // Whole seconds: the end is stored to the second, now() has microseconds.
        $left = $endsAt === null ? 0 : max(0, $endsAt->getTimestamp() - now()->getTimestamp());

        foreach ($parts as $part) {
            $seconds = min($left, $part->minutes * 60);
            $left -= $seconds;
            if ($seconds <= 0 || $part->user_id !== $userId) {
                continue;
            }

            if ($part->kind === SessionPurchase::KIND_MINUTES) {
                $total = $part->minutes * 60;
                $result['refund_main'] += intdiv($part->main_cents * $seconds, $total);
                $result['refund_bonus'] += intdiv($part->bonus_cents * $seconds, $total);
            } elseif ($part->expires_at?->isFuture() && ($minutes = intdiv($seconds, 60)) > 0) {
                TimePackage::query()->create(['user_id' => $userId, 'tariff_id' => $part->tariff_id, 'name' => $part->name, 'minutes_left' => $minutes, 'expires_at' => $part->expires_at]);
                $result['kept_minutes'] += $minutes;
            }
        }
        SessionPurchase::query()->where('session_id', $sessionId)->delete();

        if ($result['refund_main'] + $result['refund_bonus'] > 0) {
            $balance = MemberBalance::query()->where('user_id', $userId)->lockForUpdate()->first();
            if ($balance !== null) {
                $balance->update([
                    'balance' => AgentTariffPurchaseService::formatCents(AgentTariffPurchaseService::amountToCents((string) $balance->balance) + $result['refund_main']),
                    'bonus_balance' => AgentTariffPurchaseService::formatCents(AgentTariffPurchaseService::amountToCents((string) $balance->bonus_balance) + $result['refund_bonus']),
                ]);
            }
            // Refunded money was not spent: the cumulative discount counts it back.
            $this->loyalty->subtractSpending($userId, $result['refund_main']);
        }

        $customer = User::query()->find($userId);
        if ($result['refund_main'] + $result['refund_bonus'] > 0) {
            $this->activity->record('time.refunded', __('Возврат за неиспользованное время на ПК :computer', ['computer' => $computer->displayName()]), $actorId, $customer, [
                'main_balance_amount' => AgentTariffPurchaseService::formatCents($result['refund_main']),
                'bonus_balance_amount' => AgentTariffPurchaseService::formatCents($result['refund_bonus']),
                'session_id' => $sessionId,
            ]);
        }
        if ($result['kept_minutes'] > 0) {
            $this->activity->record('time.package_kept', __('Сохранено :count мин. абонемента', ['count' => $result['kept_minutes']]), $actorId, $customer, [
                'minutes' => $result['kept_minutes'],
                'session_id' => $sessionId,
            ]);
        }

        return $result;
    }
}
