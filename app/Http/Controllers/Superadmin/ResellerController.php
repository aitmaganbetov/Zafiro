<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Reseller;
use App\Services\PlatformLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Resellers: the superadmin sets their percent and discount, records payouts and unlinks clubs. */
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
}
