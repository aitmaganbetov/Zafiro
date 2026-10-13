<?php

namespace App\Http\Controllers;

use App\Services\ClubNotifications;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __construct(private CurrentClub $currentClub) {}

    public function index(ClubNotifications $notifications): JsonResponse
    {
        $items = $notifications->for($this->currentClub->membership());

        return response()->json([
            'unread_count' => $items->where('unread', true)->count(),
            'items' => $items->map(fn (array $item): array => [
                ...$item,
                'at' => $item['at']->toIso8601String(),
                'at_human' => $item['at']->locale('ru')->diffForHumans(),
            ])->all(),
        ]);
    }

    public function markSeen(): JsonResponse
    {
        $membership = $this->currentClub->membership();
        $membership->forceFill(['notifications_seen_at' => now()])->save();

        return response()->json(['unread_count' => 0]);
    }
}
