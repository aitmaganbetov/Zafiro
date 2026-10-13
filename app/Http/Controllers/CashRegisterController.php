<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpenCashShiftRequest;
use App\Http\Requests\StoreBalanceTopUpRequest;
use App\Http\Requests\StoreCashTransactionRequest;
use App\Http\Requests\StorePosSaleRequest;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\CashRegisterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CashRegisterController extends Controller
{
    public function __construct(private CashRegisterService $cash) {}

    public function index(): View
    {
        $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->first();
        $products = Product::query()->select(['id', 'name', 'category', 'price', 'quantity'])->where('quantity', '>', 0)->orderBy('name')->get();
        $users = User::query()
            ->whereHas('currentMembership')
            ->with('currentMembership.balance')
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        return view('cash-register.index', ['shift' => $shift, 'totals' => $shift ? $this->cash->totals($shift) : null, 'products' => $products, 'users' => $users]);
    }

    public function open(OpenCashShiftRequest $request): RedirectResponse
    {
        $this->cash->open((float) $request->validated('opening_balance'), $request->user()?->getKey());

        return back()->with('status', 'Смена открыта');
    }

    public function transaction(StoreCashTransactionRequest $request): RedirectResponse
    {
        $this->cash->transact($request->validated(), $request->user()?->getKey());

        return back()->with('status', 'Операция проведена');
    }

    public function sale(StorePosSaleRequest $request): RedirectResponse
    {
        $transaction = $this->cash->sale($request->validated('items'), $request->user()?->getKey());

        return redirect()->route('cash-register.receipt', $transaction)
            ->with('status', 'Продажа проведена. Распечатайте чек.');
    }

    public function topUpBalance(StoreBalanceTopUpRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->cash->topUpBalance(
            (int) $data['user_id'],
            (float) $data['amount'],
            $data['balance_type'],
            $data['payment_method'],
            $request->user()?->getKey(),
        );

        return back()->with('status', 'Баланс пользователя пополнен.')->with('cash_view', 'balance');
    }

    public function receipt(CashTransaction $cashTransaction): View
    {
        abort_unless($cashTransaction->type === CashTransaction::TYPE_INCOME, 404);

        return view('cash-register.receipt', [
            'transaction' => $cashTransaction->load('shift'),
        ]);
    }

    public function xReport(): View
    {
        $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->firstOrFail();

        return view('cash-register.report', ['shift' => $shift, 'totals' => $this->cash->totals($shift), 'reportType' => 'X']);
    }

    public function close(CashShift $cashShift): RedirectResponse
    {
        if (! request()->session()->pull("z_report_printed.{$cashShift->id}", false)) {
            return redirect()->route('cash-register.z-report', $cashShift)
                ->withErrors(['shift' => 'Сначала распечатайте Z-отчёт. Без печати смена не закрывается.']);
        }

        $shift = $this->cash->close($cashShift, request()->user()?->getKey());

        return redirect()->route('cash-register.z-report', $shift);
    }

    public function zReport(CashShift $cashShift): View
    {
        $nonce = Str::random(40);
        request()->session()->put("z_report_nonce.{$cashShift->id}", $nonce);

        return view('cash-register.report', [
            'shift' => $cashShift,
            'totals' => $this->cash->totals($cashShift),
            'reportType' => 'Z',
            'printNonce' => $nonce,
        ]);
    }

    public function markZReportPrinted(Request $request, CashShift $cashShift): JsonResponse
    {
        abort_unless($cashShift->status === CashShift::STATUS_OPEN, 422);

        $nonce = (string) $request->input('nonce');
        $expected = (string) $request->session()->pull("z_report_nonce.{$cashShift->id}");
        abort_unless($expected !== '' && hash_equals($expected, $nonce), 419);

        $request->session()->put("z_report_printed.{$cashShift->id}", true);

        return response()->json(['printed' => true]);
    }
}
