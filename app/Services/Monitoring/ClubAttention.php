<?php

namespace App\Services\Monitoring;

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubSubscription;
use Illuminate\Support\Facades\DB;

/** Clubs that need a look: PCs suddenly gone offline, failing webhooks, a subscription about to end. */
class ClubAttention
{
    /** At least this many PCs online a quarter of an hour ago, so a small club turning off one PC is not reported. */
    private const OFFLINE_MIN_ONLINE = 3;

    private const WEBHOOK_FAILURES = 3;

    private const SUBSCRIPTION_DAYS = 3;

    /** @return list<array{club_id: int, club: string, kind: string, text: string}> */
    public function issues(): array
    {
        $now = now();
        $subscribed = ClubSubscription::query()->active()->pluck('club_id')->flip();

        $online = ClubComputer::query()->withoutGlobalScope('club')
            ->where('last_seen_at', '>=', $now->copy()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS))
            ->selectRaw('club_id, COUNT(*) AS aggregate')->groupBy('club_id')->pluck('aggregate', 'club_id');
        $before = DB::table('club_load_snapshots')
            ->where('captured_at', '>=', $now->copy()->subMinutes(20))->where('captured_at', '<', $now->copy()->subMinutes(10))
            ->selectRaw('club_id, MAX(online) AS online')->groupBy('club_id')->pluck('online', 'club_id');

        $issues = [];
        foreach ($before as $clubId => $was) {
            $is = (int) ($online[$clubId] ?? 0);
            if ($was >= self::OFFLINE_MIN_ONLINE && $is * 2 < $was && $subscribed->has($clubId)) {
                $issues[] = ['club_id' => (int) $clubId, 'kind' => 'offline', 'text' => __('ПК в сети: :now, 15 минут назад было :was. Возможно, у клуба пропал интернет.', ['now' => $is, 'was' => (int) $was])];
            }
        }

        $failures = DB::table('webhook_deliveries')->where('status', 'failed')->where('created_at', '>=', $now->copy()->subDay())
            ->selectRaw('club_id, COUNT(*) AS aggregate')->groupBy('club_id')
            ->havingRaw('COUNT(*) >= ?', [self::WEBHOOK_FAILURES])->pluck('aggregate', 'club_id');
        foreach ($failures as $clubId => $count) {
            $issues[] = ['club_id' => (int) $clubId, 'kind' => 'webhooks', 'text' => __('Не доставлено вебхуков за сутки: :count', ['count' => (int) $count])];
        }

        $until = $now->copy()->addDays(self::SUBSCRIPTION_DAYS);
        $ending = ClubSubscription::query()->active()
            ->where(fn ($query) => $query
                ->where(fn ($paid) => $paid->where('status', 'active')->where('current_period_ends_at', '<=', $until))
                ->orWhere(fn ($trial) => $trial->where('status', 'trialing')->where('trial_ends_at', '<=', $until)))
            ->get(['club_id', 'status', 'current_period_ends_at', 'trial_ends_at']);
        foreach ($ending as $subscription) {
            $ends = $subscription->status === 'trialing' ? $subscription->trial_ends_at : $subscription->current_period_ends_at;
            $issues[] = ['club_id' => $subscription->club_id, 'kind' => 'subscription', 'text' => __('Подписка заканчивается :date', ['date' => $ends->format('d.m.Y H:i')])];
        }

        $names = Club::query()->whereIn('id', array_column($issues, 'club_id'))->pluck('name', 'id');

        return array_map(fn (array $issue): array => [...$issue, 'club' => $names[$issue['club_id']] ?? '#'.$issue['club_id']], $issues);
    }
}
