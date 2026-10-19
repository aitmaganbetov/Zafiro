<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Requests\OpenCashShiftRequest;
use App\Http\Requests\StoreBalanceTopUpRequest;
use App\Http\Requests\StoreCashTransactionRequest;
use App\Http\Requests\StorePosSaleRequest;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\MemberBalance;
use App\Services\CashRegisterService;
use App\Services\CurrentClub;
use App\Services\ShiftReport;
use App\Services\TopUpBonus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The cash register of the staff app: the same CashRegisterService and validation as the panel. */
class CashController extends StaffApiController
{
    public function __construct(private CashRegisterService $cash, private ShiftReport $reports) {}

    public function show(CurrentClub $currentClub): JsonResponse
    {
        $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->first();

        return response()->json([
            'currency' => data_get($currentClub->settings(), 'finances.currency', 'KZT'),
            'shift' => $shift === null ? null : self::shift($shift),
            'totals' => $shift === null ? null : $this->totals($shift),
            'report' => $shift === null ? null : $this->report($shift),
            'transactions' => $shift === null ? [] : $shift->transactions()->latest('id')->limit(20)->get(['id', 'type', 'amount', 'description', 'created_at'])
                ->map(fn (CashTransaction $row): array => self::transaction($row))->all(),
        ]);
    }

    public function open(OpenCashShiftRequest $request): JsonResponse
    {
        $shift = $this->cash->open((float) $request->validated('opening_balance'), $request->user()->getKey());

        return response()->json(['shift' => self::shift($shift)], 201);
    }

    /** Closes the shift and answers with its Z-report (the panel prints it before closing; the app shows it after). */
    public function close(Request $request, CashShift $shift): JsonResponse
    {
        $shift = $this->cash->close($shift, $request->user()->getKey());

        return response()->json([
            'shift' => ['id' => $shift->getKey(), 'closed_at' => self::at($shift->closed_at), 'closing_balance' => self::money($shift->closing_balance)],
            'totals' => $this->totals($shift),
            'report' => $this->report($shift),
        ]);
    }

    public function topUp(StoreBalanceTopUpRequest $request, TopUpBonus $topUpBonus): JsonResponse
    {
        $data = $request->validated();
        $bonus = $data['balance_type'] === 'balance' ? $topUpBonus->for((float) $data['amount'])['cents'] : 0;
        $this->cash->topUpBalance((int) $data['user_id'], (float) $data['amount'], $data['balance_type'], $data['payment_method'], $request->user()->getKey());
        $balance = MemberBalance::query()->where('user_id', (int) $data['user_id'])->first();

        return response()->json([
            'message' => $bonus > 0
                ? __('Баланс пользователя пополнен. Бонус за пополнение: :amount.', ['amount' => number_format($bonus / 100, 2, '.', ' ')])
                : __('Баланс пользователя пополнен.'),
            'bonus' => self::money($bonus / 100),
            'balance' => self::money($balance?->balance),
            'bonus_balance' => self::money($balance?->bonus_balance),
        ]);
    }

    public function sale(StorePosSaleRequest $request): JsonResponse
    {
        $transaction = $this->cash->sale($request->validated('items'), $request->user()->getKey());

        return response()->json([
            'id' => $transaction->getKey(),
            'amount' => self::money($transaction->amount),
            'items' => collect($transaction->receipt_data['items'] ?? [])->map(fn (array $item): array => [
                'name' => $item['name'],
                'quantity' => (int) $item['quantity'],
                'price' => self::money($item['price']),
                'total' => self::money($item['total']),
            ])->all(),
        ], 201);
    }

    public function transact(StoreCashTransactionRequest $request): JsonResponse
    {
        $transaction = $this->cash->transact($request->validated(), $request->user()->getKey());

        return response()->json(self::transaction($transaction), 201);
    }

    /** @return array<string, mixed> */
    public static function transaction(CashTransaction $row): array
    {
        return ['id' => $row->getKey(), 'type' => $row->type, 'amount' => self::money($row->amount), 'description' => $row->description, 'at' => self::at($row->created_at)];
    }

    /** @return array<string, mixed> */
    private static function shift(CashShift $shift): array
    {
        return ['id' => $shift->getKey(), 'opened_at' => self::at($shift->opened_at), 'opening_balance' => self::money($shift->opening_balance)];
    }

    /** @return array<string, string> */
    private function totals(CashShift $shift): array
    {
        return array_map(fn (float $value): string => self::money($value), $this->cash->totals($shift));
    }

    /** @return array<string, mixed> */
    private function report(CashShift $shift): array
    {
        $report = $this->reports->for($shift);

        return [
            'kinds' => array_map(fn (array $kind): array => ['label' => $kind['label'], 'count' => $kind['count'], 'amount' => self::money($kind['amount'])], $report['kinds']),
            'items' => array_map(fn (array $item): array => ['name' => $item['name'], 'quantity' => $item['quantity'], 'amount' => self::money($item['amount'])], $report['items']),
            'items_total' => self::money($report['items_total']),
            'discount' => self::money($report['discount']),
            'staff' => array_map(fn (array $row): array => ['name' => $row['name'], 'count' => $row['count'], 'income' => self::money($row['income']), 'expense' => self::money($row['expense'])], $report['staff']),
        ];
    }
}
