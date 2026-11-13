<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Reseller;
use App\Models\User;
use App\Services\PlatformLogger;
use App\Support\ResellerHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Resellers: the superadmin creates them (with a new account or an existing one by phone), sets
 * their percent and discount, records payouts, unlinks clubs and deletes resellers.
 */
class ResellerController extends Controller
{
    public function __construct(private PlatformLogger $platformLog) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($search, '\\%_').'%';

        return view('superadmin.resellers.index', [
            'search' => $search,
            'resellers' => Reseller::query()
                ->with(['user:id,name,phone', 'clubs:id,name,reseller_id,reseller_attached_at'])
                ->withSum(['invoices as earned' => fn ($query) => $query->where('status', 'paid')], 'reseller_amount')
                ->withSum('payouts as paid_out', 'amount')
                ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match->where('code', 'like', $like)->orWhere('name', 'like', $like)))
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $digits = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
        $digits = strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true) ? substr($digits, 1) : $digits;
        $request->merge(['phone' => $digits === '' ? '' : '+7'.$digits, 'code' => Reseller::normalizeCode($request->input('code'))]);
        $existing = User::query()->where('phone', $request->input('phone'))->first();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+7\d{10}$/'],
            // An existing account keeps its password; a new one needs it.
            'password' => [$existing === null ? 'required' : 'nullable', 'string', 'min:6'],
            'code' => ['required', 'string', 'regex:'.Reseller::CODE_PATTERN, Rule::unique('resellers', 'code')],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'discount_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ], [
            'phone.regex' => __('Введите корректный номер телефона.'),
            'code.regex' => __('Код — от 3 до 32 символов: латинские буквы, цифры и дефис, начинается с буквы или цифры.'),
            'code.unique' => __('Этот код уже занят — выберите другой.'),
        ]);
        if ($existing !== null && Reseller::query()->where('user_id', $existing->getKey())->exists()) {
            throw ValidationException::withMessages(['phone' => __('Аккаунт с этим телефоном уже реселлер.')]);
        }

        $reseller = DB::transaction(function () use ($existing, $data, $request): Reseller {
            $user = $existing ?? User::query()->create(['name' => $data['name'], 'phone' => $data['phone'], 'password' => Hash::make($data['password'])]);
            $reseller = new Reseller(['code' => $data['code'], 'name' => $data['name']]);
            $reseller->forceFill([
                'user_id' => $user->getKey(),
                'commission_percent' => $data['commission_percent'],
                'discount_percent' => (int) $data['discount_percent'],
            ])->save();
            $this->platformLog->record('superadmin.reseller_created', __('Новый реселлер: :name (код :code)', ['name' => $reseller->name, 'code' => $reseller->code]), $request->user(), null, $reseller);

            return $reseller;
        });

        return back()->with('status', $existing === null
            ? __('Реселлер :code создан. Он входит на :host со своим телефоном и паролем.', ['code' => $reseller->code, 'host' => $this->host()])
            : __('Реселлер :code создан для существующего аккаунта: он входит на :host со своим паролем.', ['code' => $reseller->code, 'host' => $this->host()]));
    }

    public function update(Request $request, Reseller $reseller): RedirectResponse
    {
        $data = $request->validate([
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'discount_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($reseller, $data, $request): void {
            $reseller->forceFill([
                'commission_percent' => $data['commission_percent'],
                'discount_percent' => (int) $data['discount_percent'],
                'is_active' => $request->boolean('is_active'),
            ])->save();
            $changes = collect($reseller->getChanges())->except('updated_at')->map(fn (mixed $value): string => (string) $value)->all();
            if ($changes !== []) {
                $this->platformLog->record('superadmin.reseller_updated', __('Изменён реселлер :code', ['code' => $reseller->code]), $request->user(), null, $reseller, $changes);
            }
        });

        return back()->with('status', __('Реселлер :code сохранён.', ['code' => $reseller->code]));
    }

    public function payout(Request $request, Reseller $reseller): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($reseller, $data, $request): void {
            $payout = $reseller->payouts()->create([...$data, 'created_by' => $request->user()->getKey()]);
            $this->platformLog->record('superadmin.reseller_payout', __('Выплата реселлеру :code: :amount ₸', ['code' => $reseller->code, 'amount' => $payout->amount]), $request->user(), null, $reseller, ['amount' => (float) $payout->amount]);
        });

        return back()->with('status', __('Выплата реселлеру :code записана.', ['code' => $reseller->code]));
    }

    /** The club is no longer the reseller's: new bills go without their discount and percent. */
    public function detach(Request $request, Reseller $reseller, Club $club): RedirectResponse
    {
        abort_unless($club->reseller_id === $reseller->getKey(), 404);

        DB::transaction(function () use ($reseller, $club, $request): void {
            $club->forceFill(['reseller_id' => null, 'reseller_attached_at' => null])->save();
            $this->platformLog->record('superadmin.reseller_detached', __('Клуб :club отвязан от реселлера :code', ['club' => $club->name, 'code' => $reseller->code]), $request->user(), $club, $reseller);
        });

        return back()->with('status', __('Клуб :club отвязан от реселлера.', ['club' => $club->name]));
    }

    /**
     * The account stays and is no longer a reseller: its clubs are unlinked (new bills go without
     * the discount and percent), its payouts go, paid bills stay without the reseller.
     */
    public function destroy(Request $request, Reseller $reseller): RedirectResponse
    {
        DB::transaction(function () use ($reseller, $request): void {
            $clubs = Club::query()->where('reseller_id', $reseller->getKey())->update(['reseller_id' => null, 'reseller_attached_at' => null]);
            $this->platformLog->record('superadmin.reseller_deleted', __('Удалён реселлер :name (код :code)', ['name' => $reseller->name, 'code' => $reseller->code]), $request->user(), null, null, [
                'code' => $reseller->code,
                'user_id' => $reseller->user_id,
                'clubs' => $clubs,
            ]);
            $reseller->delete();
        });

        return redirect()->route('superadmin.resellers.index')->with('status', __('Реселлер :code удалён.', ['code' => $reseller->code]));
    }

    private function host(): string
    {
        return ResellerHost::domain() ?? url('/reseller');
    }
}
