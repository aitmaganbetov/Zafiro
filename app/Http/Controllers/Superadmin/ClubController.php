<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetCurrentClub;
use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\PlatformLog;
use App\Models\SaasPlan;
use App\Models\User;
use App\Services\AgentUpdate;
use App\Services\Monitoring\ClubAttention;
use App\Services\PlatformLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** All clubs of the platform: subscription, members, PCs; read-only view of a club's panel. */
class ClubController extends Controller
{
    public function __construct(private PlatformLogger $platformLog) {}

    /** Activity-log events that start a PC session (a tariff, time by the minute, a guest at the cash register). */
    private const SESSION_EVENTS = ['tariff.purchased', 'time.purchased', 'computer.guest_session'];

    /** sort key => [column, default direction] */
    private const SORTS = [
        'name' => ['name', 'asc'],
        'online' => ['online_count', 'desc'],
        'busy' => ['busy_count', 'desc'],
        'agent_requests' => ['agent_requests', 'desc'],
        'api_requests' => ['api_requests', 'desc'],
        'last_login' => ['last_login_at', 'desc'],
        'sessions' => ['sessions_today', 'desc'],
        'webhooks' => ['webhook_failures', 'desc'],
        'ends' => ['subscription_ends_at', 'asc'],
    ];

    /** Requests per minute of a club are averaged over this many minutes. */
    private const LOAD_MINUTES = 15;

    public function index(Request $request, ClubAttention $attention, AgentUpdate $agentUpdate): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'subscription' => ['nullable', 'in:active,inactive'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'name';
        [$sortColumn, $direction] = self::SORTS[$sort];
        $direction = $filters['direction'] ?? $direction;
        $activeSubscription = fn (Builder $query) => $query->active();
        $now = now();
        $loadFrom = intdiv($now->getTimestamp(), 60) - self::LOAD_MINUTES;
        $clubComputers = fn () => ClubComputer::query()->withoutGlobalScope('club')->selectRaw('COUNT(*)')->whereColumn('club_computers.club_id', 'clubs.id');

        $clubs = Club::query()
            ->select(['id', 'name', 'slug', 'created_at'])
            ->with('subscription.plan:id,name')
            ->addSelect(['owner_name' => User::query()->select('users.name')
                ->join('club_memberships', 'club_memberships.user_id', '=', 'users.id')
                ->whereColumn('club_memberships.club_id', 'clubs.id')
                ->where('club_memberships.role', 'owner')
                ->limit(1)])
            ->addSelect(['computers_count' => $clubComputers()])
            ->addSelect(['online_count' => $clubComputers()->where('last_seen_at', '>=', $now->copy()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS))])
            ->addSelect(['busy_count' => $clubComputers()->whereNotNull('current_session_id')->where('session_ends_at', '>', $now)
                ->where(fn (Builder $query) => $query->whereNotNull('current_session_user_id')->orWhere('session_guest', true))])
            ->addSelect(['agent_requests' => DB::table('club_request_metrics')->selectRaw('COALESCE(SUM(agent_requests), 0)')
                ->whereColumn('club_request_metrics.club_id', 'clubs.id')->where('minute', '>=', $loadFrom)])
            ->addSelect(['api_requests' => DB::table('club_request_metrics')->selectRaw('COALESCE(SUM(api_requests), 0)')
                ->whereColumn('club_request_metrics.club_id', 'clubs.id')->where('minute', '>=', $loadFrom)])
            ->addSelect(['last_login_at' => ActivityLog::query()->withoutGlobalScope('club')->selectRaw('MAX(created_at)')
                ->whereColumn('activity_logs.club_id', 'clubs.id')->where('event', 'auth.login')])
            ->addSelect(['sessions_today' => ActivityLog::query()->withoutGlobalScope('club')->selectRaw('COUNT(*)')
                ->whereColumn('activity_logs.club_id', 'clubs.id')->whereIn('event', self::SESSION_EVENTS)->where('created_at', '>=', $now->copy()->subDay())])
            ->addSelect(['webhook_failures' => DB::table('webhook_deliveries')->selectRaw('COUNT(*)')
                ->whereColumn('webhook_deliveries.club_id', 'clubs.id')->where('status', 'failed')->where('created_at', '>=', $now->copy()->subDay())])
            ->addSelect(['subscription_ends_at' => ClubSubscription::query()
                ->selectRaw("CASE WHEN status = 'trialing' THEN trial_ends_at ELSE current_period_ends_at END")
                ->whereColumn('club_subscriptions.club_id', 'clubs.id')->limit(1)])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->when(($filters['subscription'] ?? null) === 'active', fn (Builder $query) => $query->whereHas('subscription', $activeSubscription))
            ->when(($filters['subscription'] ?? null) === 'inactive', fn (Builder $query) => $query->whereDoesntHave('subscription', $activeSubscription))
            ->orderBy($sortColumn, $direction)
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // PCs on an old agent, for this page only: versions are compared as versions, not as text.
        $latest = $agentUpdate->latestVersion();
        $outdated = [];
        if ($latest !== null) {
            ClubComputer::query()->withoutGlobalScope('club')->whereIn('club_id', $clubs->pluck('id'))->whereNotNull('agent_version')
                ->selectRaw('club_id, agent_version, COUNT(*) AS aggregate')->groupBy('club_id', 'agent_version')->get()
                ->each(function ($row) use ($agentUpdate, $latest, &$outdated): void {
                    if ($agentUpdate->isOutdated($row->agent_version, $latest)) {
                        $outdated[$row->club_id] = ($outdated[$row->club_id] ?? 0) + (int) $row->aggregate;
                    }
                });
        }

        return view('superadmin.clubs.index', [
            'clubs' => $clubs,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'sorts' => self::SORTS,
            'loadMinutes' => self::LOAD_MINUTES,
            'outdated' => $outdated,
            'latestAgent' => $latest,
            'issues' => $attention->issues(),
            'totals' => [
                'clubs' => Club::query()->count(),
                'active' => ClubSubscription::query()->active()->count(),
                'trial' => ClubSubscription::query()->where('status', 'trialing')->where('trial_ends_at', '>', $now)->count(),
            ],
        ]);
    }

    public function show(Club $club): View
    {
        $club->load('subscription.plan');

        return view('superadmin.clubs.show', [
            'club' => $club,
            'members' => ClubMembership::query()->withoutGlobalScope('club')
                ->where('club_id', $club->getKey())
                ->with('user:id,name,phone,blocked_at,is_superadmin')
                ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 WHEN 'operator' THEN 3 ELSE 4 END")
                ->orderBy('id')
                ->paginate(30),
            'computers' => ClubComputer::query()->withoutGlobalScope('club')->where('club_id', $club->getKey())
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) AS online', [now()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS)])
                ->first(),
            // Share of busy PCs by weekday and hour (club time) over four weeks of 5-minute snapshots.
            'heatmap' => DB::table('club_load_snapshots')->where('club_id', $club->getKey())
                ->where('captured_at', '>=', now()->subDays(28))->where('computers', '>', 0)
                ->selectRaw('weekday, hour, AVG(busy * 100.0 / computers) AS busy, AVG(online) AS online')
                ->groupBy('weekday', 'hour')->get()
                ->mapWithKeys(fn ($cell) => [$cell->weekday.'-'.$cell->hour => ['busy' => (int) round((float) $cell->busy), 'online' => round((float) $cell->online, 1)]]),
            'plans' => SaasPlan::query()->orderBy('price_monthly')->get(['id', 'name', 'price_monthly', 'is_active']),
            'activity' => ActivityLog::query()->withoutGlobalScope('club')->where('club_id', $club->getKey())
                ->with(['user:id,name', 'club:id,name'])->latest('id')->limit(15)->get(),
            'platformLogs' => PlatformLog::query()->where('club_id', $club->getKey())
                ->with(['user:id,name', 'club:id,name'])->latest('id')->limit(15)->get(),
        ]);
    }

    /** Pay-as-agreed: the club gets N more days of an active subscription (from today if it has run out). */
    public function extend(Request $request, Club $club): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'saas_plan_id' => ['nullable', 'integer', 'exists:saas_plans,id'],
        ]);

        DB::transaction(function () use ($club, $data, $request): void {
            $subscription = ClubSubscription::query()->where('club_id', $club->getKey())->lockForUpdate()->first()
                ?? (new ClubSubscription)->forceFill(['club_id' => $club->getKey(), 'started_at' => now()]);
            $from = $subscription->current_period_ends_at?->isFuture() ? $subscription->current_period_ends_at : now();
            $planId = $data['saas_plan_id'] ?? $subscription->saas_plan_id ?? SaasPlan::query()->where('is_active', true)->orderBy('price_monthly')->value('id');

            $subscription->forceFill([
                'saas_plan_id' => $planId,
                'status' => 'active',
                'current_period_ends_at' => $from->copy()->addDays($data['days']),
                'canceled_at' => null,
            ])->save();

            $this->platformLog->record('superadmin.subscription_extended', __('Подписка клуба :club продлена на :days дн. (до :date)', [
                'club' => $club->name,
                'days' => $data['days'],
                'date' => $subscription->current_period_ends_at->format('d.m.Y'),
            ]), $request->user(), $club, $subscription, ['days' => $data['days'], 'until' => $subscription->current_period_ends_at->toIso8601String()]);
        });

        return back()->with('status', __('Подписка продлена.'));
    }

    /** The club loses access to its panel and agents until the subscription is extended again. */
    public function suspend(Request $request, Club $club): RedirectResponse
    {
        $subscription = $club->subscription;
        abort_if($subscription === null, 404);

        DB::transaction(function () use ($club, $subscription, $request): void {
            $subscription->forceFill(['status' => 'suspended', 'canceled_at' => now()])->save();
            $this->platformLog->record('superadmin.subscription_suspended', __('Подписка клуба :club приостановлена', ['club' => $club->name]), $request->user(), $club, $subscription);
        });

        return back()->with('status', __('Подписка приостановлена.'));
    }

    public function changePlan(Request $request, Club $club): RedirectResponse
    {
        $data = $request->validate(['saas_plan_id' => ['required', 'integer', 'exists:saas_plans,id']]);
        $subscription = $club->subscription;
        abort_if($subscription === null, 404);
        $from = $subscription->plan?->name;

        DB::transaction(function () use ($club, $subscription, $data, $from, $request): void {
            $subscription->forceFill(['saas_plan_id' => $data['saas_plan_id']])->save();
            $to = SaasPlan::query()->whereKey($data['saas_plan_id'])->value('name');
            $this->platformLog->record('superadmin.subscription_plan_changed', __('Тариф клуба :club: :from → :to', ['club' => $club->name, 'from' => $from ?? '—', 'to' => $to]), $request->user(), $club, $subscription);
        });

        return back()->with('status', __('Тариф клуба изменён.'));
    }

    /**
     * Open the club's panel read-only. The visit goes to the platform log: the club's own log
     * only takes entries of its members (a database constraint), and a superadmin is not one.
     */
    public function view(Request $request, Club $club): RedirectResponse
    {
        $request->session()->put(SetCurrentClub::VIEWING_KEY, $club->getKey());
        $this->platformLog->record('superadmin.club_viewed', __('Суперадмин открыл клуб :club на просмотр', ['club' => $club->name]), $request->user(), $club);

        return redirect()->route('dashboard');
    }

    public function leave(Request $request): RedirectResponse
    {
        $clubId = $request->session()->pull(SetCurrentClub::VIEWING_KEY);

        return $clubId !== null && Club::query()->whereKey($clubId)->exists()
            ? redirect()->route('superadmin.clubs.show', $clubId)
            : redirect()->route('superadmin.clubs.index');
    }
}
