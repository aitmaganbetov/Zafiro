<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\ChatController;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\ClubComputer;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Services\CashRegisterService;
use App\Services\ClubNotifications;
use App\Services\CurrentClub;
use App\Services\GuestFeatures;
use App\Support\StaffPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Who is signed in to the staff app and what they may do; the day report of managers. */
class MeController extends StaffApiController
{
    public function __construct(private CurrentClub $currentClub) {}

    public function show(Request $request, GuestFeatures $features, ClubNotifications $notifications, CashRegisterService $cash): JsonResponse
    {
        $club = $this->currentClub->get();
        $membership = $this->currentClub->membership();
        $permissions = StaffPermissions::for($membership, $features->guestModeEnabled());
        $shift = $permissions['cash'] ? CashShift::query()->where('status', CashShift::STATUS_OPEN)->first() : null;

        return response()->json([
            'user' => ['id' => $request->user()->getKey(), 'name' => $request->user()->name, 'phone' => $request->user()->phone],
            'club' => [
                'id' => $club->getKey(),
                'name' => $club->name,
                'currency' => data_get($this->currentClub->settings(), 'finances.currency', 'KZT'),
                'timezone' => $club->timezone,
            ],
            'role' => $membership?->role,
            'permissions' => $permissions,
            'counters' => [
                'orders_new' => ShopOrder::newCount(),
                'chat_unread' => ChatController::unreadTotal(),
                'notifications_unread' => $notifications->for($membership)->where('unread', true)->count(),
            ],
            'shift' => $shift === null ? null : [
                'id' => $shift->getKey(),
                'opened_at' => self::at($shift->opened_at),
                'balance' => self::money($cash->totals($shift)['balance']),
            ],
        ]);
    }

    /** Today and the last 7 days in the club's local days (one grouped query), PCs, low stock. */
    public function today(): JsonResponse
    {
        $today = now($this->currentClub->get()->timezone ?: config('app.timezone'))->startOfDay();
        $days = collect(range(6, 0))->map(fn (int $ago) => $today->copy()->subDays($ago));
        $utc = fn ($moment): string => $moment->copy()->utc()->format('Y-m-d H:i:s');
        $columns = [];
        $bindings = [];
        foreach ($days as $i => $day) {
            $columns[] = "SUM(CASE WHEN created_at >= ? AND created_at < ? THEN amount ELSE 0 END) AS total_{$i}";
            $columns[] = "SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS count_{$i}";
            $range = [$utc($day), $utc($day->copy()->addDay())];
            array_push($bindings, ...$range, ...$range);
        }
        $totals = CashTransaction::query()
            ->where('created_at', '>=', $utc($days->first()))->where('created_at', '<', $utc($today->copy()->addDay()))
            ->selectRaw('type, '.implode(', ', $columns), $bindings)->groupBy('type')->toBase()->get()->keyBy('type');
        $value = fn (int $day, string $type, string $column = 'total'): float => (float) ($totals[$type]->{"{$column}_{$day}"} ?? 0);

        $computers = ClubComputer::query()->get(['id', 'status', 'is_reserved', 'last_seen_at', 'current_session_id', 'current_session_user_id', 'session_guest', 'session_ends_at']);

        return response()->json([
            'currency' => data_get($this->currentClub->settings(), 'finances.currency', 'KZT'),
            'today' => [
                'income' => self::money($value(6, CashTransaction::TYPE_INCOME)),
                'expense' => self::money($value(6, CashTransaction::TYPE_EXPENSE)),
                'transactions' => (int) ($value(6, CashTransaction::TYPE_INCOME, 'count') + $value(6, CashTransaction::TYPE_EXPENSE, 'count')),
            ],
            'week' => $days->map(fn ($day, int $i): array => [
                'date' => $day->toDateString(),
                'income' => self::money($value($i, CashTransaction::TYPE_INCOME)),
                'expense' => self::money($value($i, CashTransaction::TYPE_EXPENSE)),
            ])->values()->all(),
            'computers' => [
                'total' => $computers->count(),
                'online' => $computers->filter(fn (ClubComputer $pc): bool => $pc->connectionStatus() === 'online')->count(),
                'busy' => $computers->filter(fn (ClubComputer $pc): bool => $pc->hasPaidSession())->count(),
                'reserved' => $computers->filter(fn (ClubComputer $pc): bool => $pc->currentStatus() === 'reserved')->count(),
            ],
            'low_stock' => Product::query()->where('quantity', '<', 5)->orderBy('quantity')->orderBy('name')->limit(20)
                ->get(['id', 'name', 'quantity'])->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'quantity' => (int) $product->quantity])->all(),
            'recent_transactions' => CashTransaction::query()->latest('id')->limit(10)->get(['id', 'type', 'amount', 'description', 'created_at'])
                ->map(fn (CashTransaction $row): array => CashController::transaction($row))->all(),
        ]);
    }
}
