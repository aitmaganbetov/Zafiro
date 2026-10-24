<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\LoyaltyProgram;
use App\Support\ClubPermissions;
use App\Support\ClubRoles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /** Discount and opening balance of a customer: the "customer_money" right (managers by default). */
    public static function canSetMoney(): bool
    {
        return ClubPermissions::allows(app(CurrentClub::class)->membership(), 'customer_money');
    }

    /** The club owner may top up a customer's balance without the cash register (editing the customer). */
    public static function canTopUpWithoutCash(): bool
    {
        return app(CurrentClub::class)->membership()?->role === 'owner';
    }

    public function index(LoyaltyProgram $loyalty): View
    {
        // Customers only; staff are on the Workers page.
        $users = ClubMembership::query()
            ->whereNotIn('role', ClubRoles::STAFF)
            ->with(['user:id,name,phone,email,is_superadmin', 'balance'])
            ->orderBy('user_id')
            ->paginate(25);

        $staffElsewhere = ClubMembership::query()->withoutGlobalScope('club')
            ->whereIn('user_id', $users->pluck('user_id'))->whereIn('role', ClubRoles::STAFF)
            ->distinct()->pluck('user_id')
            ->merge($users->filter(fn (ClubMembership $membership): bool => $membership->user->isSuperadmin())->pluck('user_id'))
            ->flip();

        return view('users', ['users' => $users, 'staffElsewhere' => $staffElsewhere, 'canSetMoney' => self::canSetMoney(), 'canTopUp' => self::canTopUpWithoutCash(), 'loyalty' => $loyalty]);
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

    /**
     * Name and email of a customer (discount: managers only). The owner may also top up a balance
     * here without the cash register: no cash transaction, the top-up is logged as the owner's.
     */
    public function update(Request $request, ClubMembership $membership, ActivityLogger $activity): RedirectResponse
    {
        $user = $this->customer($membership);
        $accountLocked = $this->isAccountLocked($user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique((new User)->getTable(), 'email')->ignore($user->getKey())],
            'discount' => [self::canSetMoney() ? 'nullable' : 'prohibited', 'numeric', 'min:0', 'max:100'],
            'top_up_amount' => [self::canTopUpWithoutCash() ? 'nullable' : 'prohibited', 'numeric', 'min:0.01', 'max:10000000'],
            'top_up_balance' => ['nullable', 'required_with:top_up_amount', Rule::in(['balance', 'bonus_balance'])],
            // The same amount adds to the balance or (the owner only too) writes it off.
            'balance_action' => [self::canTopUpWithoutCash() ? 'nullable' : 'prohibited', Rule::in(['top_up', 'write_off'])],
        ], [
            'discount.prohibited' => __('Скидку клиента задаёт менеджер.'),
            'top_up_amount.prohibited' => __('Пополнять и списывать баланс без кассы может только владелец клуба.'),
            'balance_action.prohibited' => __('Пополнять и списывать баланс без кассы может только владелец клуба.'),
        ]);
        // A worker of another club: the name and email are of the account — this club changes only
        // its own discount and balance.
        if ($accountLocked && ($data['name'] !== $user->name || ($data['email'] ?? null) !== $user->email)) {
            throw ValidationException::withMessages(['name' => __('Это сотрудник другого клуба: имя и email меняет он сам. Здесь меняются только скидка и баланс.')]);
        }
        $topUp = isset($data['top_up_amount']) ? round((float) $data['top_up_amount'], 2) : 0.0;
        $writeOff = ($data['balance_action'] ?? 'top_up') === 'write_off';

        DB::transaction(function () use ($user, $membership, $data, $topUp, $writeOff, $request, $activity): void {
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

            if ($topUp > 0 && $writeOff) {
                $balance = MemberBalance::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
                $field = $data['top_up_balance'];
                if ((float) $balance->{$field} < $topUp) {
                    throw ValidationException::withMessages(['top_up_amount' => __('На балансе клиента меньше суммы списания: :amount.', ['amount' => number_format((float) $balance->{$field}, 2, '.', ' ')])]);
                }
                $balance->decrement($field, $topUp);
                $label = $field === 'balance' ? __('основной баланс') : __('бонусный баланс');
                $activity->record('user.balance_written_off', __('Списание :balance без кассы: :user', ['balance' => $label, 'user' => $user->name]), $request->user()->getKey(), $user, [
                    'amount' => $topUp,
                    'balance_field' => $field,
                    'balance_type' => $label,
                    'new_balance' => (float) $balance->refresh()->{$field},
                    'without_cash' => true,
                ]);
            } elseif ($topUp > 0) {
                $balance = MemberBalance::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
                $field = $data['top_up_balance'];
                $balance->increment($field, $topUp);
                $label = $field === 'balance' ? __('основной баланс') : __('бонусный баланс');
                // The customer's history shows it as a top-up (the same event as at the cash register).
                $activity->record('user.balance_topped_up', __('Пополнение :balance без кассы: :user', ['balance' => $label, 'user' => $user->name]), $request->user()->getKey(), $user, [
                    'amount' => $topUp,
                    'balance_type' => $label,
                    'payment_method' => __('без кассы (владелец)'),
                    'new_balance' => (float) $balance->refresh()->{$field},
                    'without_cash' => true,
                ]);
            }
        });

        $amount = number_format($topUp, 2, '.', ' ');

        return back()->with('status', match (true) {
            $topUp > 0 && $writeOff => __('Данные клиента сохранены. С баланса списано :amount без кассы.', ['amount' => $amount]),
            $topUp > 0 => __('Данные клиента сохранены. Баланс пополнен на :amount без кассы.', ['amount' => $amount]),
            default => __('Данные клиента сохранены.'),
        });
    }

    /**
     * A new random password for a customer, shown once to the staff member (in this response only,
     * never stored in the session). The customer is signed out on every PC. Customers can also
     * recover it themselves with a code in Telegram (PasswordRecovery).
     */
    public function resetPassword(Request $request, ClubMembership $membership, ActivityLogger $activity): Response
    {
        $user = $this->customer($membership);
        // The password of a worker opens the other club's admin: never shown to this club's staff.
        abort_if($this->isAccountLocked($user), 403, __('Это сотрудник другого клуба: пароль он восстанавливает сам («Забыли пароль?»).'));
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

    /**
     * Only customers of this club. A worker of another club (or a superadmin) may play here: this
     * club changes their discount and balance, not the account (name, email, password).
     */
    private function customer(ClubMembership $membership): User
    {
        abort_if(ClubRoles::isStaff($membership->role), 403, __('Сотрудников меняют на странице «Работники».'));

        return User::query()->findOrFail($membership->user_id);
    }

    /** The account opens a club admin or the superadmin panel: its sign-in data stays its own. */
    private function isAccountLocked(User $user): bool
    {
        return $user->isSuperadmin() || ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->getKey())->whereIn('role', ClubRoles::STAFF)->exists();
    }
}
