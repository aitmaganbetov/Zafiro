<?php

namespace App\Http\Controllers;

use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\StaffShifts;
use App\Support\ClubRoles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The club's staff. Staff are users already registered in Zafiro (or invited by a link):
 * found by phone and given a staff role; removing a worker makes them a customer again.
 * Only the owner manages administrators; nobody changes the owner or their own role.
 */
class WorkerController extends Controller
{
    public function __construct(private CurrentClub $currentClub, private ActivityLogger $activity) {}

    public function index(StaffShifts $shifts): View
    {
        $workers = ClubMembership::query()
            ->whereIn('role', ClubRoles::STAFF)
            ->with('user:id,name,phone')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 WHEN 'cashier' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->paginate(50);
        $settings = $this->currentClub->settings();
        $onShift = collect(ClubRoles::SHIFT)->mapWithKeys(fn (string $role): array => [$role => $shifts->onShift($this->currentClub->id(), $role)]);

        return view('workers.index', [
            'workers' => $workers,
            'onShift' => $onShift->flatten()->all(),
            'shiftLimits' => collect(ClubRoles::SHIFT)->mapWithKeys(fn (string $role): array => [$role => ['limit' => $shifts->limit($settings, $role), 'count' => count($onShift[$role])]])->all(),
            'assignable' => $this->assignableRoles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $digits = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            $digits = substr($digits, 1);
        }
        $request->merge(['phone' => '+7'.$digits]);
        $data = $request->validate([
            'phone' => ['required', 'regex:/^\+7\d{10}$/'],
            'role' => ['required', Rule::in(array_keys($this->assignableRoles()))],
        ], ['phone.regex' => __('Введите 10 цифр номера после +7.')]);

        $user = User::query()->where('phone', $data['phone'])->first(['id', 'name']);
        if ($user === null) {
            throw ValidationException::withMessages(['phone' => __('Пользователь с таким номером не зарегистрирован в Zafiro. Отправьте ему ссылку-приглашение.')]);
        }

        DB::transaction(function () use ($user, $data, $request): void {
            $membership = ClubMembership::query()->where('user_id', $user->getKey())->lockForUpdate()->first();
            if ($membership !== null && ClubRoles::isStaff($membership->role)) {
                throw ValidationException::withMessages(['phone' => __('Этот пользователь уже работает в клубе.')]);
            }

            if ($membership === null) {
                ClubMembership::query()->create(['user_id' => $user->getKey(), 'role' => $data['role'], 'discount' => 0]);
                MemberBalance::query()->firstOrCreate(['user_id' => $user->getKey()], ['balance' => 0, 'bonus_balance' => 0]);
            } else {
                $membership->update(['role' => $data['role']]);
            }

            $this->activity->record('worker.added', __('Добавлен работник :user: :role', ['user' => $user->name, 'role' => ClubRoles::label($data['role'])]), $request->user()->getKey(), $user, ['role' => $data['role']]);
        });

        return back()->with('status', __('Работник добавлен.'));
    }

    public function update(Request $request, ClubMembership $membership): RedirectResponse
    {
        $this->ensureManageable($request, $membership);
        $role = $request->validate(['role' => ['required', Rule::in(array_keys($this->assignableRoles()))]])['role'];

        if ($membership->role !== $role) {
            $from = $membership->role;
            DB::transaction(function () use ($membership, $role, $from, $request): void {
                $membership->update(['role' => $role]);
                $this->activity->record('worker.role_changed', __('Роль работника :user: :from → :to', [
                    'user' => $membership->user?->name ?? '—', 'from' => ClubRoles::label($from), 'to' => ClubRoles::label($role),
                ]), $request->user()->getKey(), $membership->user, ['from' => $from, 'to' => $role]);
            });
        }

        return back()->with('status', __('Роль изменена.'));
    }

    /** No longer staff: the account stays in the club as a customer (balance kept). */
    public function destroy(Request $request, ClubMembership $membership): RedirectResponse
    {
        $this->ensureManageable($request, $membership);
        DB::transaction(function () use ($membership, $request): void {
            $from = $membership->role;
            $membership->update(['role' => ClubRoles::CLIENT]);
            $this->activity->record('worker.removed', __('Работник :user больше не сотрудник клуба', ['user' => $membership->user?->name ?? '—']), $request->user()->getKey(), $membership->user, ['from' => $from]);
        });

        return back()->with('status', __('Работник убран из сотрудников. Аккаунт остался клиентом клуба.'));
    }

    /** @return array<string, string> roles the signed-in member may give */
    private function assignableRoles(): array
    {
        $roles = ClubRoles::assignable();

        return $this->currentClub->membership()?->role === 'owner' ? $roles : array_diff_key($roles, ['admin' => true]);
    }

    private function ensureManageable(Request $request, ClubMembership $membership): void
    {
        abort_unless(ClubRoles::isStaff($membership->role) && $membership->role !== 'owner', 404);
        abort_if($membership->user_id === $request->user()->getKey(), 403, __('Свою роль изменить нельзя.'));
        abort_if($membership->role === 'admin' && $this->currentClub->membership()?->role !== 'owner', 403, __('Администраторов меняет только владелец.'));
    }
}
