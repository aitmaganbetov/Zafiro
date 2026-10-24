<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpenCashShiftRequest;
use App\Http\Requests\StoreBalanceTopUpRequest;
use App\Http\Requests\StoreCashTransactionRequest;
use App\Http\Requests\StorePosSaleRequest;
use App\Http\Resources\ProductResource;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CashRegisterService;
use App\Services\CurrentClub;
use App\Services\ShiftReport;
use App\Services\TopUpBonus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CashRegisterController extends Controller
{
    public function __construct(private CashRegisterService $cash) {}

    public function index(CurrentClub $currentClub): View
    {
        $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->first();
        // All columns: the photo path too, where the products table has it.
        $products = Product::query()->where('quantity', '>', 0)->orderBy('name')->get()
            ->each(fn (Product $product) => $product->setAttribute('photo_url', ProductResource::imageUrl($product, request())));
        $clubId = $currentClub->id();
        $users = User::query()
            ->join('club_memberships', fn ($join) => $join->on('club_memberships.user_id', '=', 'users.id')
                ->where('club_memberships.club_id', $clubId))
            ->leftJoin('member_balances', fn ($join) => $join->on('member_balances.user_id', '=', 'users.id')
                ->where('member_balances.club_id', $clubId))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.phone', 'member_balances.balance', 'member_balances.bonus_balance']);

        return view('cash-register.index', ['shift' => $shift, 'totals' => $shift ? $this->cash->totals($shift) : null, 'products' => $products, 'users' => $users]);
    }

    public function open(OpenCashShiftRequest $request): RedirectResponse
    {
        $this->cash->open((float) $request->validated('opening_balance'), $request->user()->getKey());

        return back()->with('status', __('Смена открыта'));
    }

    public function transaction(StoreCashTransactionRequest $request): RedirectResponse
    {
        $this->cash->transact($request->validated(), $request->user()->getKey());

        return back()->with('status', __('Операция проведена'));
    }

    public function sale(StorePosSaleRequest $request): RedirectResponse
    {
        $transaction = $this->cash->sale($request->validated('items'), $request->user()->getKey());

        return redirect()->route('cash-register.receipt', $transaction)
            ->with('status', __('Продажа проведена. Распечатайте чек.'));
    }

    public function topUpBalance(StoreBalanceTopUpRequest $request, TopUpBonus $topUpBonus): RedirectResponse
    {
        $data = $request->validated();
        $bonus = $data['balance_type'] === 'balance' ? $topUpBonus->for((float) $data['amount'])['cents'] : 0;
        $this->cash->topUpBalance(
            (int) $data['user_id'],
            (float) $data['amount'],
            $data['balance_type'],
            $data['payment_method'],
            $request->user()->getKey(),
        );

        $status = $bonus > 0
            ? __('Баланс пользователя пополнен. Бонус за пополнение: :amount.', ['amount' => number_format($bonus / 100, 2, '.', ' ')])
            : __('Баланс пользователя пополнен.');

        return back()->with('status', $status)->with('cash_view', 'balance');
    }

    public function receipt(CashTransaction $cashTransaction): View
    {
        abort_unless($cashTransaction->type === CashTransaction::TYPE_INCOME, 404);

        return view('cash-register.receipt', [
            'transaction' => $cashTransaction->load(['shift', 'refund']),
            'canRefund' => self::canRefund(),
        ]);
    }

    /**
     * Refunds: the owner, admins and managers always; cashiers when the club allows it
     * (Settings → Club → Staff on shift).
     */
    public static function canRefund(): bool
    {
        $club = app(CurrentClub::class);

        return match ($club->membership()?->role) {
            'owner', 'admin', 'manager' => true,
            'cashier' => (bool) data_get($club->settings(), 'club.allow_cashier_refunds', false),
            default => false,
        };
    }

    /** Income of the open shift, newest first, with what was returned: the place to make a refund. */
    public function operations(): View
    {
        $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->firstOrFail();
        $operations = $shift->transactions()->where('type', CashTransaction::TYPE_INCOME)
            ->with('refund:id,refund_of_id,created_at')
            ->latest('id')
            ->paginate(25);

        return view('cash-register.operations', ['shift' => $shift, 'operations' => $operations, 'canRefund' => self::canRefund()]);
    }

    public function refund(Request $request, CashTransaction $cashTransaction): RedirectResponse
    {
        abort_unless(self::canRefund(), 403, __('Возвраты кассирам выключены в настройках клуба.'));
        $this->cash->refund($cashTransaction, $request->user()->getKey());

        return back()->with('status', __('Возврат проведён: :amount KZT выданы из кассы.', ['amount' => number_format((float) $cashTransaction->amount, 2, ',', ' ')]));
    }

    public function xReport(): View
    {
        $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->firstOrFail();

        return view('cash-register.report', ['shift' => $shift, 'totals' => $this->cash->totals($shift), 'details' => app(ShiftReport::class)->for($shift), 'reportType' => 'X'] + $this->people($shift));
    }

    public function close(CashShift $cashShift): RedirectResponse
    {
        if (! request()->session()->pull("z_report_printed.{$cashShift->id}", false)) {
            return redirect()->route('cash-register.z-report', $cashShift)
                ->withErrors(['shift' => __('Сначала распечатайте Z-отчёт. Без печати смена не закрывается.')]);
        }

        $shift = $this->cash->close($cashShift, request()->user()->getKey());

        return redirect()->route('cash-register.z-report', $shift);
    }

    public function zReport(CashShift $cashShift): View
    {
        $nonce = Str::random(40);
        request()->session()->put("z_report_nonce.{$cashShift->id}", $nonce);

        return view('cash-register.report', [
            'shift' => $cashShift,
            'totals' => $this->cash->totals($cashShift),
            'details' => app(ShiftReport::class)->for($cashShift),
            'reportType' => 'Z',
            'printNonce' => $nonce,
        ] + $this->people($cashShift));
    }

    /**
     * Names on the report: the cashier who opened the shift (and who closed it, when someone
     * else did) and the operator — the staff member who forms and prints this report.
     *
     * @return array{cashierName: ?string, closedByName: ?string, operatorName: string}
     */
    private function people(CashShift $shift): array
    {
        $names = User::query()->whereKey(array_filter([$shift->opened_by, $shift->closed_by]))->pluck('name', 'id');

        return [
            'cashierName' => $names->get($shift->opened_by),
            'closedByName' => $shift->closed_by !== null && (int) $shift->closed_by !== (int) $shift->opened_by ? $names->get($shift->closed_by) : null,
            'operatorName' => (string) request()->user()->name,
        ];
    }

    public function markZReportPrinted(Request $request, CashShift $cashShift, ActivityLogger $activity): JsonResponse
    {
        abort_unless($cashShift->status === CashShift::STATUS_OPEN, 422);

        $nonce = (string) $request->input('nonce');
        $expected = (string) $request->session()->pull("z_report_nonce.{$cashShift->id}");
        abort_unless($expected !== '' && hash_equals($expected, $nonce), 419);

        $request->session()->put("z_report_printed.{$cashShift->id}", true);
        $activity->record('cash.z_report_printed', __('Напечатан Z-отчёт смены #:id', ['id' => $cashShift->id]), $request->user()->getKey(), $cashShift);

        return response()->json(['printed' => true]);
    }
}
