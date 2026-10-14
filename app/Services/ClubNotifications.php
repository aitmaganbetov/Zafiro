<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ClubComputer;
use App\Models\ClubInvitation;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the notification bell contents for a club member. Notifications are derived
 * from existing data; an item is unread when it happened after the member last
 * opened the panel (club_memberships.notifications_seen_at).
 */
class ClubNotifications
{
    public const LOW_STOCK_BELOW = 5;

    public const ACTIVITY_DAYS = 7;

    private const MANAGERS = ['owner', 'admin', 'manager'];

    /** Activity events worth surfacing, with the bell icon to show. */
    private const ACTIVITY_EVENTS = [
        'auth.login_failed' => 'fa-user-lock',
        'auth.api_login_failed' => 'fa-user-lock',
        'agent.exit_pin_failed' => 'fa-lock',
        'computer.controlled' => 'fa-power-off',
        'computer.registered' => 'fa-desktop',
        'settings.updated' => 'fa-sliders',
        'zone.deleted' => 'fa-trash',
        'invitation.accepted' => 'fa-user-plus',
    ];

    /**
     * @return Collection<int, array{id: string, icon: string, title: string, body: string, url: string, at: Carbon, unread: bool}>
     */
    public function for(ClubMembership $membership): Collection
    {
        $role = $membership->role;
        $items = collect();

        if (in_array($role, self::MANAGERS, true)) {
            $items = $items->merge($this->activity($membership))
                ->merge($this->subscription($membership->club_id))
                ->merge($this->invitations());
        }

        if (in_array($role, [...self::MANAGERS, 'cashier'], true)) {
            $items = $items->merge($this->lowStock());
        }

        if (in_array($role, [...self::MANAGERS, 'operator'], true)) {
            $items = $items->merge($this->offlineComputers());
        }

        $seenAt = $membership->notifications_seen_at;

        return $items
            ->map(fn (array $item): array => $item + ['unread' => $seenAt === null || $item['at']->gt($seenAt)])
            ->sortByDesc(fn (array $item): int => $item['at']->getTimestamp())
            ->values();
    }

    /** @return list<array<string, mixed>> */
    private function activity(ClubMembership $membership): array
    {
        return ActivityLog::query()
            ->with('user:id,name')
            ->whereIn('event', array_keys(self::ACTIVITY_EVENTS))
            ->where('user_id', '!=', $membership->user_id)
            ->where('created_at', '>=', now()->subDays(self::ACTIVITY_DAYS))
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'id' => 'activity-'.$log->id,
                'icon' => self::ACTIVITY_EVENTS[$log->event],
                'title' => __(ActivityLog::EVENT_LABELS[$log->event]),
                'body' => $log->user && ! str_contains($log->description, $log->user->name)
                    ? $log->description.' · '.$log->user->name
                    : $log->description,
                'url' => route('activity-logs.index', ['event' => $log->event]),
                'at' => $log->created_at,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function lowStock(): array
    {
        $products = Product::query()
            ->where('quantity', '<', self::LOW_STOCK_BELOW)
            ->orderBy('quantity')
            ->orderBy('name')
            ->get(['id', 'name', 'quantity', 'updated_at']);

        if ($products->isEmpty()) {
            return [];
        }

        $names = $products->take(3)->map(fn (Product $product): string => __(':product (:quantity шт.)', ['product' => $product->name, 'quantity' => $product->quantity]))->implode(', ');

        return [[
            'id' => 'low-stock',
            'icon' => 'fa-boxes-stacked',
            'title' => trans_choice('Низкий остаток: :count товар|Низкий остаток: :count товара|Низкий остаток: :count товаров', $products->count()),
            'body' => $this->listWithMore($names, $products->count() - 3),
            'url' => route('warehouse'),
            'at' => $products->max('updated_at'),
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function offlineComputers(): array
    {
        $timeout = now()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS);
        $computers = ClubComputer::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $timeout)
            ->where('status', '!=', 'offline')
            ->with('zone:id,name')
            ->orderBy('club_zone_id')
            ->orderBy('number')
            ->get(['id', 'club_zone_id', 'number', 'device_name', 'last_seen_at']);

        if ($computers->isEmpty()) {
            return [];
        }

        $names = $computers->take(5)->map(fn (ClubComputer $computer): string => "{$computer->displayName()} ({$computer->zone->name})")->implode(', ');

        return [[
            'id' => 'computers-offline',
            'icon' => 'fa-plug-circle-xmark',
            'title' => __('Не на связи: :count ПК', ['count' => $computers->count()]),
            'body' => $this->listWithMore($names, $computers->count() - 5),
            'url' => route('club-computers.index'),
            'at' => $computers->max('last_seen_at')->copy()->addSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS),
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function subscription(int $clubId): array
    {
        $subscription = ClubSubscription::query()->where('club_id', $clubId)->first();
        $endsAt = match ($subscription?->status) {
            'trialing' => $subscription->trial_ends_at,
            'active' => $subscription->current_period_ends_at,
            default => null,
        };

        if ($endsAt === null || $endsAt->gt(now()->addDays(3))) {
            return [];
        }

        return [[
            'id' => 'subscription',
            'icon' => 'fa-credit-card',
            'title' => match (true) {
                $subscription->status === 'trialing' && $endsAt->isPast() => __('Пробный период закончился'),
                $subscription->status === 'trialing' => __('Пробный период заканчивается'),
                $endsAt->isPast() => __('Подписка закончилась'),
                default => __('Подписка заканчивается'),
            },
            'body' => $endsAt->isPast()
                ? __('Окончание: :date', ['date' => $endsAt->format('d.m.Y H:i')])
                : __('Действует до :date', ['date' => $endsAt->format('d.m.Y H:i')]),
            'url' => route('billing.show'),
            'at' => $endsAt->copy()->subDays(3),
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function invitations(): array
    {
        return ClubInvitation::query()
            ->whereNull('accepted_at')
            ->whereBetween('expires_at', [now(), now()->addDay()])
            ->orderBy('expires_at')
            ->get(['id', 'email', 'role', 'expires_at'])
            ->map(fn (ClubInvitation $invitation): array => [
                'id' => 'invitation-'.$invitation->id,
                'icon' => 'fa-envelope-open-text',
                'title' => __('Приглашение скоро истечёт'),
                'body' => $invitation->email
                    ? __('Роль :role, :email · до :date', ['role' => $invitation->role, 'email' => $invitation->email, 'date' => $invitation->expires_at->format('d.m H:i')])
                    : __('Роль :role · до :date', ['role' => $invitation->role, 'date' => $invitation->expires_at->format('d.m H:i')]),
                'url' => route('users.index'),
                'at' => $invitation->expires_at->copy()->subDay(),
            ])
            ->all();
    }

    private function listWithMore(string $list, int $more): string
    {
        return $more > 0 ? __(':list и ещё :count', ['list' => $list, 'count' => $more]) : $list;
    }
}
