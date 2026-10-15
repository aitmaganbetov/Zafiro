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

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'subscription' => ['nullable', 'in:active,inactive'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $activeSubscription = fn (Builder $query) => $query->active();

        $clubs = Club::query()
            ->select(['id', 'name', 'slug', 'created_at'])
            ->with('subscription.plan:id,name')
            ->addSelect(['owner_name' => User::query()->select('users.name')
                ->join('club_memberships', 'club_memberships.user_id', '=', 'users.id')
                ->whereColumn('club_memberships.club_id', 'clubs.id')
                ->where('club_memberships.role', 'owner')
                ->limit(1)])
            ->addSelect(['computers_count' => ClubComputer::query()->withoutGlobalScope('club')
                ->selectRaw('COUNT(*)')->whereColumn('club_computers.club_id', 'clubs.id')])
            ->addSelect(['online_count' => ClubComputer::query()->withoutGlobalScope('club')
                ->selectRaw('COUNT(*)')->whereColumn('club_computers.club_id', 'clubs.id')
                ->where('last_seen_at', '>=', now()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS))])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->when(($filters['subscription'] ?? null) === 'active', fn (Builder $query) => $query->whereHas('subscription', $activeSubscription))
            ->when(($filters['subscription'] ?? null) === 'inactive', fn (Builder $query) => $query->whereDoesntHave('subscription', $activeSubscription))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('superadmin.clubs.index', ['clubs' => $clubs, 'filters' => $filters]);
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
