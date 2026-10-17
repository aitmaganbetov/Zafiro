<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ClubComputer;
use App\Models\MemberBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Integrations\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Goods ordered by the customer in the PC agent: paid from the main balance, written off the
 * club stock and sent to the staff as a chat message, so the cashier brings them to the PC.
 */
class AgentShop
{
    public const MAX_LINES = 30;

    public const MAX_QUANTITY = 50;

    /** Note of the stock movement (a marker, not translated). */
    private const STOCK_NOTE = 'Заказ из агента';

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

            // The staff see the order like any customer message (chat and the bell).
            ChatMessage::query()->create([
                'customer_id' => $user->getKey(),
                'author_id' => $user->getKey(),
                'from_staff' => false,
                'body' => Str::limit(__('Заказ на ПК :computer: :items. Итого :amount, оплачено с баланса.', ['computer' => $computer->displayName(), 'items' => $items, 'amount' => $amount]), ChatMessage::MAX_LENGTH),
                'club_computer_id' => $computer->getKey(),
            ]);

            return [
                'message' => __('Заказ оплачен. Сотрудник клуба принесёт его к ПК :computer.', ['computer' => $computer->displayName()]),
                'balance' => AgentTariffPurchaseService::formatCents($main - $total),
                'amount' => $amount,
                'stock' => $stock,
            ];
        });
    }
}
