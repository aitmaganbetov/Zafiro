<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubSubscription;
use App\Models\PlatformLog;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Platform overview: clubs and subscriptions, users, PCs and agent versions, background work. */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $users = User::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN blocked_at IS NOT NULL THEN 1 ELSE 0 END) AS blocked')
            ->selectRaw('SUM(CASE WHEN is_superadmin = 1 THEN 1 ELSE 0 END) AS superadmins')
            ->first();
        $computers = ClubComputer::query()->withoutGlobalScope('club')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) AS online', [now()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS)])
            ->first();

        return view('superadmin.dashboard', [
            'clubCount' => Club::query()->count(),
            'activeClubs' => ClubSubscription::query()->active()->count(),
            'trialClubs' => ClubSubscription::query()->where('status', 'trialing')->where('trial_ends_at', '>', now())->count(),
            'users' => $users,
            'computers' => $computers,
            'agentVersions' => ClubComputer::query()->withoutGlobalScope('club')
                ->whereNotNull('agent_version')
                ->selectRaw('agent_version, COUNT(*) AS aggregate')
                ->groupBy('agent_version')->orderByDesc('aggregate')->limit(10)
                ->pluck('aggregate', 'agent_version'),
            'latestAgent' => config('club.agent.latest_version'),
            'webhooks' => WebhookDelivery::query()->withoutGlobalScope('club')
                ->where('created_at', '>=', now()->subDay())
                ->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')
                ->pluck('aggregate', 'status'),
            'queuedJobs' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'recentLogs' => PlatformLog::query()->with(['user:id,name', 'club:id,name'])->latest('id')->limit(10)->get(),
        ]);
    }
}
