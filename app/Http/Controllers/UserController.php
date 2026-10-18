<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\LoyaltyProgram;
use App\Support\ClubRoles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** Discount and opening balance of a new customer: managers only, not the front desk. */
    public static function canSetMoney(): bool
    {
        return in_array(app(CurrentClub::class)->membership()?->role, ['owner', 'admin', 'manager'], true);
    }

    public function index(LoyaltyProgram $loyalty): View
    {
        // Customers only; staff are on the Workers page.
        $users = ClubMembership::query()
            ->whereNotIn('role', ClubRoles::STAFF)
            ->with(['user:id,name,phone,email', 'balance'])
            ->orderBy('user_id')
            ->paginate(25);

        return view('users', ['users' => $users, 'canSetMoney' => self::canSetMoney(), 'loyalty' => $loyalty]);
    }

    public function store(StoreUserRequest $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validated();
        if (! self::canSetMoney()) {
            abort_if(collect(['discount', 'balance', 'bonus_balance'])->contains(fn (string $field): bool => (float) ($data[$field] ?? 0) !== 0.0), 403, __('Скидку и баланс клиента задаёт менеджер; пополнение — через кассу.'));
            $data = ['discount' => 0, 'balance' => 0, 'bonus_balance' => 0] + $data;
        }
        $actorId = $request->user()->getKey();
        DB::transaction(function () use ($data, $actorId, $activity): void {
            $user = User::query()->create(Arr::only($data, ['name', 'phone', 'email', 'password']));
            $membership = ClubMembership::query()->create([
                'user_id' => $user->getKey(),
                'role' => ClubRoles::CLIENT,
                'discount' => $data['discount'] ?? 0,
            ]);
            MemberBalance::query()->create([
                'user_id' => $user->getKey(),
                'balance' => $data['balance'] ?? 0,
                'bonus_balance' => $data['bonus_balance'] ?? 0,
            ]);
            $activity->record('user.created', __('Создан пользователь: :user', ['user' => $user->name]), $actorId, $user, [
                'phone' => $user->phone,
                'role' => $membership->role,
            ]);
        });

        return redirect()->route('users.index')->with('status', __('Пользователь добавлен.'));
    }

    /** Name and email of a customer (discount: managers only). */
    public function update(Request $request, ClubMembership $membership, ActivityLogger $activity): RedirectResponse
    {
        $user = $this->customer($membership);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique((new User)->getTable(), 'email')->ignore($user->getKey())],
            'discount' => [self::canSetMoney() ? 'nullable' : 'prohibited', 'numeric', 'min:0', 'max:100'],
        ], ['discount.prohibited' => __('Скидку клиента задаёт менеджер.')]);

        DB::transaction(function () use ($user, $membership, $data, $request, $activity): void {
            $before = ['name' => $user->name, 'email' => $user->email, 'discount' => (string) $membership->discount];
            $user->fill(['name' => $data['name'], 'email' => $data['email'] ?? null])->save();
            if (array_key_exists('discount', $data) && $data['discount'] !== null) {
                $membership->update(['discount' => $data['discount']]);
            }
            $after = ['name' => $user->name, 'email' => $user->email, 'discount' => (string) $membership->fresh()->discount];
            $changes = collect($after)->filter(fn ($value, $field) => $before[$field] !== $value)
                ->map(fn ($value, $field) => ($before[$field] ?? '—').' → '.($value ?? '—'))->all();
            if ($changes !== []) {
                $activity->record('user.updated', __('Изменён клиент: :user', ['user' => $user->name]), $request->user()->getKey(), $user, $changes);
            }
        });

        return back()->with('status', __('Данные клиента сохранены.'));
    }

    /**
     * A new random password for a customer, shown once to the staff member (in this response only,
     * never stored in the session). The customer is signed out on every PC. Customers can also
     * recover it themselves with a code in Telegram (PasswordRecovery).
     */
    public function resetPassword(Request $request, ClubMembership $membership, ActivityLogger $activity): Response
    {
        $user = $this->customer($membership);
        $password = Str::password(8, symbols: false);

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
        });

        $activity->record('user.password_reset', __('Сброшен пароль клиента :user', ['user' => $user->name]), $request->user()->getKey(), $user, [
            'delivery' => 'screen',
        ]);

        return response()->view('users-password', ['customer' => $user, 'password' => $password])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Only customers: staff of any club and superadmins are not changed from here. */
    private function customer(ClubMembership $membership): User
    {
        $user = User::query()->findOrFail($membership->user_id);
        $isStaffSomewhere = ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->getKey())->whereIn('role', ClubRoles::STAFF)->exists();
        abort_if(ClubRoles::isStaff($membership->role) || $isStaffSomewhere || $user->isSuperadmin(), 403, __('Сотрудников меняют на странице «Работники».'));

        return $user;
    }
}
