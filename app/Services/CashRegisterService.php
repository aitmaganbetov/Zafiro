<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\MemberBalance;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashRegisterService
{
    public function __construct(private ActivityLogger $activity) {}

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
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'price' => (float) $product->price,
                    'total' => (float) $product->price * $quantity,
                ];
                $product->decrement('quantity', $quantity);
                $balanceAfter = $product->refresh()->quantity;
                StockMovement::create(['product_id' => $product->id, 'user_id' => $userId, 'type' => StockMovement::TYPE_WRITE_OFF, 'quantity' => $quantity, 'balance_after' => $balanceAfter, 'note' => 'Продажа POS']);
                $this->activity->record('stock.written_off', __('Списание при продаже: :product, :quantity шт.', ['product' => $product->name, 'quantity' => $quantity]), $userId, $product, ['quantity' => $quantity, 'balance_after' => $balanceAfter, 'note' => 'Продажа POS']);
            }
            $transaction = $shift->transactions()->create([
                'user_id' => $userId,
                'type' => CashTransaction::TYPE_INCOME,
                'amount' => $total,
                'description' => 'Продажа POS',
                'receipt_data' => ['items' => $receiptItems],
            ]);
            $itemsSummary = collect($receiptItems)
                ->map(fn (array $item): string => $item['name'].' × '.$item['quantity'])
                ->implode(', ');
            $this->activity->record(
                'product.purchased',
                __('Покупка товаров: ').$itemsSummary,
                $userId,
                $transaction,
                ['amount' => $total, 'item_count' => count($receiptItems)],
            );

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
            $balance->refresh();

            $transaction = $shift->transactions()->create([
                'user_id' => $operatorId,
                'type' => CashTransaction::TYPE_INCOME,
                'amount' => $amount,
                'description' => __('Пополнение :balance: :user (:payment)', ['balance' => $balanceLabel, 'user' => $user->name, 'payment' => $paymentLabels[$paymentMethod]]),
            ]);

            $details = [
                'amount' => $amount,
                'balance_type' => $balanceLabel,
                'payment_method' => $paymentLabels[$paymentMethod],
                'new_balance' => (float) $balance->{$balanceType},
            ];
            $this->activity->record('cash.income', $transaction->description, $operatorId, $transaction, $details);
            $this->activity->record('user.balance_topped_up', $transaction->description, $operatorId, $user, $details);

            return $transaction;
        });
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
                ($isIncome ? __('Пополнение кассы: ') : __('Расход из кассы: ')).$transaction->description,
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
