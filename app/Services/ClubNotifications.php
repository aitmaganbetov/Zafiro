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
                'title' => ActivityLog::EVENT_LABELS[$log->event],
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

        $names = $products->take(3)->map(fn (Product $product): string => "{$product->name} ({$product->quantity} шт.)")->implode(', ');
        $more = $products->count() > 3 ? ' и ещё '.($products->count() - 3) : '';

        return [[
            'id' => 'low-stock',
            'icon' => 'fa-boxes-stacked',
            'title' => 'Низкий остаток: '.$products->count().' '.$this->plural($products->count(), 'товар', 'товара', 'товаров'),
            'body' => $names.$more,
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
        $more = $computers->count() > 5 ? ' и ещё '.($computers->count() - 5) : '';

        return [[
            'id' => 'computers-offline',
            'icon' => 'fa-plug-circle-xmark',
            'title' => 'Не на связи: '.$computers->count().' ПК',
            'body' => $names.$more,
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

        $label = $subscription->status === 'trialing' ? 'Пробный период' : 'Подписка';

        return [[
            'id' => 'subscription',
            'icon' => 'fa-credit-card',
            'title' => $endsAt->isPast() ? "{$label} закончился" : "{$label} заканчивается",
            'body' => ($endsAt->isPast() ? 'Окончание: ' : 'Действует до ').$endsAt->format('d.m.Y H:i'),
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
                'title' => 'Приглашение скоро истечёт',
                'body' => "Роль {$invitation->role}".($invitation->email ? ", {$invitation->email}" : '').' · до '.$invitation->expires_at->format('d.m H:i'),
                'url' => route('users.index'),
                'at' => $invitation->expires_at->copy()->subDay(),
            ])
            ->all();
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        return match (true) {
            $mod10 === 1 && $mod100 !== 11 => $one,
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => $few,
            default => $many,
        };
    }
}
