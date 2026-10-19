<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubSubscription;
use App\Services\Monitoring\Alerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('monitoring:clubs')]
#[Description('Save how many PCs of each club are online and busy (heat map) and alert on clubs gone offline')]
class SnapshotClubLoad extends Command
{
    /** A club with at least this many PCs online that drops to none is reported. */
    private const OFFLINE_ALERT_MIN_ONLINE = 3;

    public function handle(Alerts $alerts): int
    {
        $now = now();
        $counts = ClubComputer::query()->withoutGlobalScope('club')
            ->select('club_id')
            ->selectRaw('COUNT(*) AS computers')
            ->selectRaw('SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) AS online', [$now->copy()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS)])
            ->selectRaw('SUM(CASE WHEN current_session_id IS NOT NULL AND (current_session_user_id IS NOT NULL OR session_guest = 1) AND session_ends_at > ? THEN 1 ELSE 0 END) AS busy', [$now])
            ->groupBy('club_id')
            ->get()->keyBy('club_id');
        if ($counts->isEmpty()) {
            return self::SUCCESS;
        }

        $previous = DB::table('club_load_snapshots')
            ->where('captured_at', '>=', $now->copy()->subMinutes(12))->where('captured_at', '<', $now->copy()->subMinutes(2))
            ->selectRaw('club_id, MAX(online) AS online')->groupBy('club_id')
            ->pluck('online', 'club_id');
        $clubs = Club::query()->whereIn('id', $counts->keys())->get(['id', 'name', 'timezone'])->keyBy('id');

        $rows = [];
        foreach ($counts as $clubId => $count) {
            $local = $now->copy()->setTimezone($clubs->get($clubId)?->getAttributes()['timezone'] ?: config('app.timezone'));
            $rows[] = ['club_id' => $clubId, 'captured_at' => $now, 'weekday' => $local->isoWeekday(), 'hour' => $local->hour,
                'computers' => (int) $count->computers, 'online' => (int) $count->online, 'busy' => (int) $count->busy];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('club_load_snapshots')->insert($chunk);
        }

        // Every PC of a club going offline at once: most likely the club lost its Internet.
        $subscribed = ClubSubscription::query()->active()->whereIn('club_id', $counts->keys())->pluck('club_id')->flip();
        foreach ($counts as $clubId => $count) {
            $name = $clubs->get($clubId)?->name ?? '#'.$clubId;
            $wentOffline = (int) $count->online === 0 && (int) ($previous[$clubId] ?? 0) >= self::OFFLINE_ALERT_MIN_ONLINE && $subscribed->has($clubId);
            if ($wentOffline || (int) $count->online > 0) {
                $alerts->update('club-offline.'.$clubId, $wentOffline,
                    fn () => __('Клуб :club: все ПК ушли офлайн (было в сети: :count). Возможно, у клуба пропал интернет.', ['club' => $name, 'count' => (int) $previous[$clubId]]),
                    fn () => __('Клуб :club снова в сети: ПК онлайн — :count.', ['club' => $name, 'count' => (int) $count->online]));
            }
        }

        return self::SUCCESS;
    }
}
