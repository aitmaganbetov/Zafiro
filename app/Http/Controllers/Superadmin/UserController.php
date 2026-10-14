<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Every account of the platform: clubs and roles, blocking, superadmin rights. */
class UserController extends Controller
{
    public function __construct(private PlatformLogger $platformLog) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:blocked,superadmin'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $digits = preg_replace('/\D+/', '', $search);

        $users = User::query()
            ->select(['id', 'name', 'phone', 'is_superadmin', 'blocked_at', 'created_at'])
            ->with(['memberships' => fn ($query) => $query->withoutGlobalScope('club')->select(['id', 'club_id', 'user_id', 'role'])->with('club:id,name')])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('name', 'like', "%{$search}%")
                ->when(strlen($digits) >= 3, fn (Builder $phone) => $phone->orWhere('phone', 'like', "%{$digits}%"))))
            ->when(($filters['filter'] ?? null) === 'blocked', fn (Builder $query) => $query->whereNotNull('blocked_at'))
            ->when(($filters['filter'] ?? null) === 'superadmin', fn (Builder $query) => $query->where('is_superadmin', true))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('superadmin.users.index', ['users' => $users, 'filters' => $filters]);
    }

    /** The account cannot sign in; its sessions and API tokens (agents, apps) stop working at once. */
    public function block(Request $request, User $user): RedirectResponse
    {
        $this->guardOther($request, $user);
        if ($user->isSuperadmin()) {
            throw ValidationException::withMessages(['user' => __('Сначала снимите с пользователя права суперадмина.')]);
        }

        DB::transaction(function () use ($request, $user): void {
            $user->forceFill(['blocked_at' => now()])->save();
            $user->tokens()->delete();
            // Sessions live in the database (SESSION_DRIVER=database); with another driver the
            // check in SetCurrentClub still signs the user out on their next request.
            $sessions = config('session.table', 'sessions');
            if (Schema::hasTable($sessions)) {
                DB::table($sessions)->where('user_id', $user->getKey())->delete();
            }
            $this->platformLog->record('superadmin.user_blocked', __('Пользователь :user заблокирован', ['user' => $user->name]), $request->user(), null, $user);
        });

        return back()->with('status', __('Пользователь заблокирован.'));
    }

    public function unblock(Request $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $user->forceFill(['blocked_at' => null])->save();
            $this->platformLog->record('superadmin.user_unblocked', __('Пользователь :user разблокирован', ['user' => $user->name]), $request->user(), null, $user);
        });

        return back()->with('status', __('Пользователь разблокирован.'));
    }

    public function grant(Request $request, User $user): RedirectResponse
    {
        if ($user->isBlocked()) {
            throw ValidationException::withMessages(['user' => __('Заблокированному пользователю нельзя выдать права суперадмина.')]);
        }

        DB::transaction(function () use ($request, $user): void {
            $user->forceFill(['is_superadmin' => true])->save();
            $this->platformLog->record('superadmin.granted', __('Пользователю :user выданы права суперадмина', ['user' => $user->name]), $request->user(), null, $user);
        });

        return back()->with('status', __('Права суперадмина выданы.'));
    }

    public function revoke(Request $request, User $user): RedirectResponse
    {
        $this->guardOther($request, $user);

        DB::transaction(function () use ($request, $user): void {
            $user->forceFill(['is_superadmin' => false])->save();
            $this->platformLog->record('superadmin.revoked', __('У пользователя :user сняты права суперадмина', ['user' => $user->name]), $request->user(), null, $user);
        });

        return back()->with('status', __('Права суперадмина сняты.'));
    }

    // A superadmin cannot lock themselves out: another superadmin has to do it.
    private function guardOther(Request $request, User $user): void
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => __('Это действие нельзя применить к своему аккаунту.')]);
        }
    }
}
