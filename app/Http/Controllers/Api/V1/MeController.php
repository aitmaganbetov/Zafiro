<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\AuthenticateApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** The club and the permissions of the calling key: a cheap way to test a key. */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);

        return response()->json(['data' => [
            'club' => ['id' => $key['club']['id'], 'name' => $key['club']['name'], 'slug' => $key['club']['slug']],
            'key' => [
                'prefix' => $key['prefix'],
                'scopes' => $key['scopes'],
                'expires_at' => $key['expires_at'] === null ? null : Carbon::createFromTimestamp($key['expires_at'])->toIso8601String(),
                'rate_limit_per_minute' => min((int) ($key['rate_limit_per_minute'] ?? config('integrations.default_rate_limit_per_minute')), (int) config('integrations.max_rate_limit_per_minute')),
            ],
        ]]);
    }
}
