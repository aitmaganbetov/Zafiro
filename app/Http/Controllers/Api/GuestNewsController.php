<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;

/** News for the agent's home screen: Zafiro news (first card) and the club's news (second card). */
class GuestNewsController extends Controller
{
    public const LIMIT = 10;

    public function __invoke(CurrentClub $currentClub): JsonResponse
    {
        $columns = ['id', 'club_id', 'title', 'body', 'image_path', 'created_at'];
        $item = fn (News $news): array => [
            'id' => $news->id,
            'title' => $news->title,
            'body' => $news->body,
            'image_url' => $news->imageUrl(),
            'published_at' => $news->created_at?->utc()->toIso8601String(),
        ];

        return response()->json([
            'platform' => News::query()->platform()->published()->latest()->latest('id')->limit(self::LIMIT)->get($columns)->map($item)->values(),
            'club' => News::query()->ofClub($currentClub->id())->published()->latest()->latest('id')->limit(self::LIMIT)->get($columns)->map($item)->values(),
        ]);
    }
}
