<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\MemberBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Integrations\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashRegisterService
{
    public function __construct(private ActivityLogger $activity, private WebhookDispatcher $webhooks, private TopUpBonus $topUpBonus) {}

    public function sale(array $items, int $userId): CashTransaction
    {
        return DB::transaction(function () use ($items, $userId) {
            $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->lockForUpdate()->first();
            if (! $shift) {
                throw ValidationException::withMessages(['items' => __('Сначала откройте смену.')]);
            }
            $total = 0.0;
            $receiptItems = [];
            foreach ($items as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item['product_id']);
                $quantity = (int) $item['quantity'];
                if ($quantity > $product->quantity) {
                    throw ValidationException::withMessages(['items' => __('Недостаточно товара: :product.', ['product' => $product->name])]);
                }
                $total += (float) $product->price * $quantity;
                $receiptItems[] = [
                    // The product comes back to the stock on a refund.
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'price' => (float) $product->price,
                    'total' => (float) $product->price * $quantity,
                ];
                $product->decrement('quantity', $quantity);
                $balanceAfter = $product->refresh()->quantity;
                StockMovement::create(['product_id' => $product->id, 'user_id' => $userId, 'type' => StockMovement::TYPE_WRITE_OFF, 'quantity' => $quantity, 'balance_after' => $balanceAfter, 'note' => 'Продажа POS']);
                $this->activity->record('stock.written_off', __('Списание при продаже: :product, :quantity шт.', ['product' => $product->name, 'quantity' => $quantity]), $userId, $product, ['quantity' => $quantity, 'balance_after' => $balanceAfter, 'note' => 'Продажа POS']);
                $this->webhooks->dispatch('stock.changed', ['product_id' => $product->id, 'sku' => $product->sku, 'change' => -$quantity, 'quantity' => $balanceAfter, 'reason' => 'sale']);
            }
            $transaction = $shift->transactions()->create([
                'user_id' => $userId,
                'type' => CashTransaction::TYPE_INCOME,
                'amount' => $total,
                'description' => CashTransaction::POS_SALE,
                'receipt_data' => ['items' => $receiptItems],
            ]);
            $itemsSummary = collect($receiptItems)
                ->map(fn (array $item): string => $item['name'].' × '.$item['quantity'])
                ->implode(', ');
            $this->activity->record(
                'product.purchased',
                __('Покупка товаров: :items', ['items' => $itemsSummary]),
                $userId,
                $transaction,
                ['amount' => $total, 'item_count' => count($receiptItems)],
            );
            $this->webhooks->dispatch('sale.completed', ['sale_id' => $transaction->id, 'cash_shift_id' => $shift->id, 'amount' => number_format($total, 2, '.', ''), 'items' => $receiptItems]);

            return $transaction;
        });
    }

    public function open(float $balance, int $userId): CashShift
    {
        return DB::transaction(function () use ($balance, $userId) {
            if (CashShift::query()->where('status', CashShift::STATUS_OPEN)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['opening_balance' => __('Смена уже открыта.')]);
            }
            $shift = CashShift::create(['opened_by' => $userId, 'status' => CashShift::STATUS_OPEN, 'open_marker' => true, 'opening_balance' => $balance, 'opened_at' => now()]);
            $this->activity->record('cash.shift_opened', __('Открыта кассовая смена'), $userId, $shift, ['opening_balance' => $balance]);

            return $shift;
        });
    }

    public function topUpBalance(int $userId, float $amount, string $balanceType, string $paymentMethod, int $operatorId): CashTransaction
    {
        return DB::transaction(function () use ($userId, $amount, $balanceType, $paymentMethod, $operatorId): CashTransaction {
            $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->lockForUpdate()->first();
            if (! $shift) {
                throw ValidationException::withMessages(['amount' => __('Сначала откройте смену.')]);
            }

            $balance = MemberBalance::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $user = $balance->user()->firstOrFail();
            $balanceLabel = $balanceType === 'balance' ? __('основной баланс') : __('бонусный баланс');
            $paymentLabels = ['cash' => __('наличные'), 'card' => __('карта'), 'qr' => __('QR-код')];
            $balance->increment($balanceType, $amount);
            // Topping up the main balance may add a bonus (Settings → Finances); it is not cash income.
            $bonus = $balanceType === 'balance' ? $this->topUpBonus->for($amount) : ['percent' => 0.0, 'cents' => 0];
            if ($bonus['cents'] > 0) {
                $balance->increment('bonus_balance', $bonus['cents'] / 100);
            }
            $balance->refresh();

            $transaction = $shift->transactions()->create([
                'user_id' => $operatorId,
                'type' => CashTransaction::TYPE_INCOME,
                'amount' => $amount,
                'description' => __('Пополнение :balance: :user (:payment)', ['balance' => $balanceLabel, 'user' => $user->name, 'payment' => $paymentLabels[$paymentMethod]]),
                // A refund takes the amount (and the top-up bonus) back from this customer's balance.
                'receipt_data' => ['top_up' => ['user_id' => $user->getKey(), 'balance_type' => $balanceType, 'bonus' => $bonus['cents'] / 100]],
            ]);

            $details = [
                'amount' => $amount,
                'balance_type' => $balanceLabel,
                'payment_method' => $paymentLabels[$paymentMethod],
                'new_balance' => (float) $balance->{$balanceType},
            ];
            if ($bonus['cents'] > 0) {
                $details += ['bonus_amount' => $bonus['cents'] / 100, 'bonus_percent' => $bonus['percent'], 'new_bonus_balance' => (float) $balance->bonus_balance];
            }
            $this->activity->record('cash.income', $transaction->description, $operatorId, $transaction, $details);
            $this->activity->record('user.balance_topped_up', $transaction->description, $operatorId, $user, $details);

            return $transaction;
        });
    }

    /**
     * Returns an income of the open shift to the customer: the money leaves the cash register as
     * an expense of the shift. A sale puts its goods back to the stock; a top-up takes its amount
     * (and the bonus it gave, as far as it is left) from the customer's balance.
     */
    public function refund(CashTransaction $income, int $userId): CashTransaction
    {
        return DB::transaction(function () use ($income, $userId): CashTransaction {
            $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->lockForUpdate()->first();
            $income = CashTransaction::query()->lockForUpdate()->findOrFail($income->getKey());
            $kind = $income->refundKind();
            if ($kind === null) {
                throw ValidationException::withMessages(['refund' => __('Эту операцию вернуть нельзя.')]);
            }
            if ($shift === null || $income->cash_shift_id !== $shift->getKey()) {
                throw ValidationException::withMessages(['refund' => __('Вернуть можно только операцию открытой смены.')]);
            }
            if ($income->refund()->exists()) {
                throw ValidationException::withMessages(['refund' => __('Эта операция уже возвращена.')]);
            }
            if ((float) $income->amount > $this->balance($shift)) {
                throw ValidationException::withMessages(['refund' => __('В кассе недостаточно средств.')]);
            }

            $details = ['amount' => (float) $income->amount, 'receipt' => $income->getKey()];
            if ($kind === 'sale') {
                foreach ($income->receipt_data['items'] as $line) {
                    $this->returnToStock($line, $userId);
                }
            } else {
                $topUp = $income->receipt_data['top_up'];
                $field = $topUp['balance_type'] === 'bonus_balance' ? 'bonus_balance' : 'balance';
                $balance = MemberBalance::query()->where('user_id', $topUp['user_id'])->lockForUpdate()->first();
                if ($balance === null || (float) $balance->{$field} < (float) $income->amount) {
                    throw ValidationException::withMessages(['refund' => __('Клиент уже потратил часть пополнения: на балансе меньше суммы возврата.')]);
                }
                $balance->decrement($field, (float) $income->amount);
                $bonus = min((float) ($topUp['bonus'] ?? 0), (float) $balance->refresh()->bonus_balance);
                if ($bonus > 0) {
                    $balance->decrement('bonus_balance', $bonus);
                }
                $balance->refresh();
                $details += ['balance_field' => $field, 'new_balance' => (float) $balance->{$field}, 'bonus_taken_back' => $bonus];
                if (($customer = $balance->user()->first()) !== null) {
                    $this->activity->record('user.balance_refunded', __('Возврат пополнения: :user', ['user' => $customer->name]), $userId, $customer, $details);
                }
            }

            $refund = $shift->transactions()->create([
                'refund_of_id' => $income->getKey(),
                'user_id' => $userId,
                'type' => CashTransaction::TYPE_EXPENSE,
                'amount' => $income->amount,
                'description' => __('Возврат по чеку №:number', ['number' => $income->getKey()]),
            ]);
            $this->activity->record('cash.refund', $refund->description, $userId, $refund, $details);

            return $refund;
        });
    }

    /** @param  array{product_id?: int, name?: string, quantity?: int}  $line */
    private function returnToStock(array $line, int $userId): void
    {
        $quantity = (int) ($line['quantity'] ?? 0);
        // Sales before refunds kept only the product name.
        $product = isset($line['product_id'])
            ? Product::query()->lockForUpdate()->find($line['product_id'])
            : Product::query()->lockForUpdate()->where('name', $line['name'] ?? '')->first();
        if ($product === null || $quantity <= 0) {
            return;
        }

        $product->increment('quantity', $quantity);
        $balanceAfter = $product->refresh()->quantity;
        StockMovement::create(['product_id' => $product->id, 'user_id' => $userId, 'type' => StockMovement::TYPE_RECEIPT, 'quantity' => $quantity, 'balance_after' => $balanceAfter, 'note' => 'Возврат POS']);
        $this->activity->record('stock.received', __('Возврат товара на склад: :product, :quantity шт.', ['product' => $product->name, 'quantity' => $quantity]), $userId, $product, ['quantity' => $quantity, 'balance_after' => $balanceAfter, 'note' => 'Возврат POS']);
        $this->webhooks->dispatch('stock.changed', ['product_id' => $product->id, 'sku' => $product->sku, 'change' => $quantity, 'quantity' => $balanceAfter, 'reason' => 'refund']);
    }

    public function transact(array $data, int $userId): CashTransaction
    {
        return DB::transaction(function () use ($data, $userId) {
            $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->lockForUpdate()->first();
            if (! $shift) {
                throw ValidationException::withMessages(['amount' => __('Сначала откройте смену.')]);
            }
            if ($data['type'] === CashTransaction::TYPE_EXPENSE && (float) $data['amount'] > $this->balance($shift)) {
                throw ValidationException::withMessages(['amount' => __('В кассе недостаточно средств.')]);
            }
            $transaction = $shift->transactions()->create($data + ['user_id' => $userId]);
            $isIncome = $transaction->type === CashTransaction::TYPE_INCOME;
            $this->activity->record(
                $isIncome ? 'cash.income' : 'cash.expense',
                $isIncome
                    ? __('Пополнение кассы: :description', ['description' => $transaction->description])
                    : __('Расход из кассы: :description', ['description' => $transaction->description]),
                $userId,
                $transaction,
                ['amount' => (float) $transaction->amount],
            );

            return $transaction;
        });
    }

    public function close(CashShift $shift, int $userId): CashShift
    {
        return DB::transaction(function () use ($shift, $userId) {
            $shift = CashShift::query()->lockForUpdate()->findOrFail($shift->id);
            if ($shift->status !== CashShift::STATUS_OPEN) {
                throw ValidationException::withMessages(['shift' => __('Смена уже закрыта.')]);
            }
            $shift->update(['closed_by' => $userId, 'status' => CashShift::STATUS_CLOSED, 'open_marker' => null, 'closing_balance' => $this->balance($shift), 'closed_at' => now()]);
            $this->activity->record('cash.shift_closed', __('Закрыта кассовая смена'), $userId, $shift, ['closing_balance' => (float) $shift->closing_balance]);

            return $shift;
        });
    }

    public function totals(CashShift $shift): array
    {
        $sums = $shift->transactions()
            ->selectRaw(
                'SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as income, SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as expense',
                [CashTransaction::TYPE_INCOME, CashTransaction::TYPE_EXPENSE],
            )
            ->toBase()
            ->first();
        $income = (float) $sums->income;
        $expense = (float) $sums->expense;

        return ['income' => $income, 'expense' => $expense, 'balance' => (float) $shift->opening_balance + $income - $expense];
    }

    private function balance(CashShift $shift): float
    {
        return $this->totals($shift)['balance'];
    }
}
