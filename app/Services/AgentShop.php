<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Models\MemberBalance;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Integrations\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods ordered by the customer in the PC agent: paid from the main balance, written off the
 * club stock and listed for the staff on the "Orders" page, so the cashier brings them to the PC.
 * Staff mark an order handed over, or cancel it: the money and the goods come back.
 */
class AgentShop
{
    public const MAX_LINES = 30;

    public const MAX_QUANTITY = 50;

    /** Note of the stock movement (a marker, not translated). */
    private const STOCK_NOTE = 'Заказ из агента';

    private const CANCEL_NOTE = 'Отмена заказа из агента';

    public function __construct(private ActivityLogger $activity, private WebhookDispatcher $webhooks) {}

    /**
     * @param  array<int, int>  $quantities  product id => quantity
     * @return array{message: string, balance: string, amount: string, stock: array<int, int>}
     */
    public function purchase(User $user, ClubComputer $computer, array $quantities): array
    {
        ksort($quantities);

        return DB::transaction(function () use ($user, $computer, $quantities): array {
            // Products are locked in id order, so two orders cannot deadlock.
            $products = Product::query()->whereKey(array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== count($quantities)) {
                throw ValidationException::withMessages(['items' => __('Товар больше не продаётся. Обновите магазин.')]);
            }

            $total = 0;
            $lines = [];
            foreach ($quantities as $id => $quantity) {
                $product = $products->get($id);
                if ($quantity > $product->quantity) {
                    throw ValidationException::withMessages(['items' => __('Недостаточно товара: :product.', ['product' => $product->name])]);
                }
                $price = AgentTariffPurchaseService::amountToCents((string) $product->price);
                $total += $price * $quantity;
                $lines[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'price' => AgentTariffPurchaseService::formatCents($price),
                    'total' => AgentTariffPurchaseService::formatCents($price * $quantity),
                ];
            }

            $balance = MemberBalance::query()->where('user_id', $user->getKey())->lockForUpdate()->first();
            $main = $balance === null ? 0 : AgentTariffPurchaseService::amountToCents((string) $balance->balance);
            if ($balance === null || $main < $total) {
                throw ValidationException::withMessages(['balance' => __('Недостаточно средств на основном счёте.')]);
            }
            $balance->update(['balance' => AgentTariffPurchaseService::formatCents($main - $total)]);

            $stock = [];
            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                $product->decrement('quantity', $line['quantity']);
                $stock[$product->id] = (int) $product->quantity;
                StockMovement::create(['product_id' => $product->id, 'user_id' => $user->getKey(), 'type' => StockMovement::TYPE_WRITE_OFF, 'quantity' => $line['quantity'], 'balance_after' => $product->quantity, 'note' => self::STOCK_NOTE]);
                $this->webhooks->dispatch('stock.changed', ['product_id' => $product->id, 'sku' => $product->sku, 'change' => -$line['quantity'], 'quantity' => (int) $product->quantity, 'reason' => 'sale']);
            }

            $amount = AgentTariffPurchaseService::formatCents($total);
            $items = collect($lines)->map(fn (array $line): string => $line['name'].' × '.$line['quantity'])->implode(', ');
            $this->activity->record('shop.purchased', __('Заказ из агента на ПК :computer: :items', ['computer' => $computer->displayName(), 'items' => $items]), $user->getKey(), $computer, [
                'amount' => $amount,
                'items' => $lines,
                'computer' => $computer->displayName(),
            ]);

            // The staff see it on the "Orders" page (and in the menu badge and the bell).
            ShopOrder::query()->create([
                'user_id' => $user->getKey(),
                'club_computer_id' => $computer->getKey(),
                'computer_name' => $computer->displayName(),
                'items' => $lines,
                'amount' => $amount,
                'status' => ShopOrder::STATUS_NEW,
            ]);

            return [
                'message' => __('Заказ оплачен. Сотрудник клуба принесёт его к ПК :computer.', ['computer' => $computer->displayName()]),
                'balance' => AgentTariffPurchaseService::formatCents($main - $total),
                'amount' => $amount,
                'stock' => $stock,
            ];
        });
    }

    /** The staff brought the order to the PC. */
    public function handOver(ShopOrder $order, int $staffId): ShopOrder
    {
        return DB::transaction(function () use ($order, $staffId): ShopOrder {
            $order = $this->lockNew($order);
            $order->forceFill(['status' => ShopOrder::STATUS_DONE, 'handled_by' => $staffId, 'handled_at' => now()])->save();
            $this->activity->record('shop.order_done', __('Заказ №:id выдан на ПК :computer', ['id' => $order->id, 'computer' => $order->computer_name ?? '—']), $staffId, $order, ['amount' => (string) $order->amount]);

            return $order;
        });
    }

    /** The order cannot be served: the money goes back to the main balance and the goods to the stock. */
    public function cancel(ShopOrder $order, int $staffId): ShopOrder
    {
        return DB::transaction(function () use ($order, $staffId): ShopOrder {
            $order = $this->lockNew($order);
            $lines = collect($order->items)->filter(fn (mixed $line): bool => is_array($line) && isset($line['product_id'], $line['quantity']));
            // Products that still exist get their quantity back (in id order, as when ordering).
            $products = Product::query()->whereKey($lines->pluck('product_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $product = $products->get((int) $line['product_id']);
                if ($product === null) {
                    continue;
                }
                $product->increment('quantity', (int) $line['quantity']);
                StockMovement::create(['product_id' => $product->id, 'user_id' => $staffId, 'type' => StockMovement::TYPE_RECEIPT, 'quantity' => (int) $line['quantity'], 'balance_after' => $product->quantity, 'note' => self::CANCEL_NOTE]);
                $this->webhooks->dispatch('stock.changed', ['product_id' => $product->id, 'sku' => $product->sku, 'change' => (int) $line['quantity'], 'quantity' => (int) $product->quantity, 'reason' => 'receipt']);
            }

            $balance = MemberBalance::query()->where('user_id', $order->user_id)->lockForUpdate()->first();
            if ($balance !== null) {
                $balance->update(['balance' => AgentTariffPurchaseService::formatCents(
                    AgentTariffPurchaseService::amountToCents((string) $balance->balance) + AgentTariffPurchaseService::amountToCents((string) $order->amount)
                )]);
            }
            $order->forceFill(['status' => ShopOrder::STATUS_CANCELLED, 'handled_by' => $staffId, 'handled_at' => now()])->save();
            // The customer is the subject: the refund shows in their history in the agent.
            $this->activity->record('shop.order_cancelled', __('Заказ №:id отменён, :amount возвращено на счёт клиента', ['id' => $order->id, 'amount' => (string) $order->amount]), $staffId, User::query()->find($order->user_id), ['amount' => (string) $order->amount, 'order_id' => $order->id]);

            return $order;
        });
    }

    private function lockNew(ShopOrder $order): ShopOrder
    {
        $order = ShopOrder::query()->lockForUpdate()->findOrFail($order->getKey());
        if ($order->status !== ShopOrder::STATUS_NEW) {
            throw ValidationException::withMessages(['order' => __('Заказ уже обработан.')]);
        }

        return $order;
    }
}
