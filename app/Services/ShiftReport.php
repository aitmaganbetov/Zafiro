<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\User;

/**
 * What a cash shift consists of, for the X / Z report: income by kind (goods, balance
 * top-ups, guest sessions, other), the goods sold (name, quantity, sum) and who took how much.
 */
class ShiftReport
{
    private const POS_SALE = CashTransaction::POS_SALE;

    /**
     * @return array{kinds: list<array{label: string, count: int, amount: float}>, items: list<array{name: string, quantity: int, amount: float, stock: ?int}>, items_total: float, discount: float, refunds: array{count: int, amount: float}, staff: list<array{name: string, count: int, income: float, expense: float}>}
     */
    public function for(CashShift $shift): array
    {
        $transactions = CashTransaction::query()->where('cash_shift_id', $shift->getKey());
        $income = (clone $transactions)->where('type', CashTransaction::TYPE_INCOME);

        // A cash income is a top-up or a guest session when its log entry says so.
        $logged = fn (string $key) => (clone $income)->whereIn('id', ActivityLog::query()
            ->where('event', 'cash.income')
            ->where('subject_type', (new CashTransaction)->getMorphClass())
            ->whereNotNull('details->'.$key)
            ->select('subject_id'));
        $kinds = [
            [__('Продажа товаров'), (clone $income)->where('description', self::POS_SALE)],
            [__('Пополнение балансов'), $logged('balance_type')],
            [__('Гостевые сессии'), $logged('computers')],
        ];
        $rows = [];
        $counted = 0;
        $countedAmount = 0.0;
        foreach ($kinds as [$label, $query]) {
            $sum = $query->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')->first();
            $rows[] = ['label' => $label, 'count' => (int) $sum->count, 'amount' => (float) $sum->amount];
            $counted += (int) $sum->count;
            $countedAmount += (float) $sum->amount;
        }
        $all = (clone $income)->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')->first();
        if ((int) $all->count > $counted) {
            $rows[] = ['label' => __('Прочий приход'), 'count' => (int) $all->count - $counted, 'amount' => round((float) $all->amount - $countedAmount, 2)];
        }

        // Refunds: expenses of the shift that return an income (goods back to the stock, a top-up).
        $refunds = (clone $transactions)->whereNotNull('refund_of_id')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')->first();

        // Goods: the lines of each sale's receipt (JSON), summed for the one shift; returned sales are not sold.
        $items = [];
        $discount = 0.0;
        $sales = (clone $income)->where('description', self::POS_SALE)->whereNotNull('receipt_data')
            ->whereNotIn('id', CashTransaction::query()->whereNotNull('refund_of_id')->select('refund_of_id'));
        foreach ($sales->cursor(['receipt_data']) as $sale) {
            $receipt = is_array($sale->receipt_data) ? $sale->receipt_data : [];
            $discount += (float) ($receipt['discount_amount'] ?? 0);
            foreach ($receipt['items'] ?? [] as $line) {
                $name = (string) ($line['name'] ?? '—');
                $items[$name] ??= ['name' => $name, 'quantity' => 0, 'amount' => 0.0, 'product_id' => null];
                $items[$name]['product_id'] ??= isset($line['product_id']) ? (int) $line['product_id'] : null;
                $items[$name]['quantity'] += (int) ($line['quantity'] ?? 0);
                $items[$name]['amount'] += (float) ($line['total'] ?? 0);
            }
        }
        usort($items, fn (array $a, array $b): int => $b['amount'] <=> $a['amount'] ?: strcmp($a['name'], $b['name']));
        // What is left in the stock now (a service has no stock).
        $stock = Product::withTrashed()->whereKey(array_filter(array_column($items, 'product_id')))->pluck('quantity', 'id');
        $items = array_map(function (array $item) use ($stock): array {
            $item['stock'] = $item['product_id'] !== null && isset($stock[$item['product_id']]) ? (int) $stock[$item['product_id']] : null;
            unset($item['product_id']);

            return $item;
        }, $items);

        $staff = (clone $transactions)
            ->selectRaw("user_id, COUNT(*) AS count, COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income, COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense")
            ->groupBy('user_id')
            ->get();
        $names = User::query()->whereKey($staff->pluck('user_id')->filter())->pluck('name', 'id');

        return [
            'kinds' => $rows,
            'items' => array_values($items),
            'items_total' => round(array_sum(array_column($items, 'amount')), 2),
            'discount' => round($discount, 2),
            'refunds' => ['count' => (int) $refunds->count, 'amount' => (float) $refunds->amount],
            'staff' => $staff->map(fn ($row): array => [
                'name' => $names[$row->user_id] ?? '—',
                'count' => (int) $row->count,
                'income' => (float) $row->income,
                'expense' => (float) $row->expense,
            ])->sortByDesc('income')->values()->all(),
        ];
    }
}
