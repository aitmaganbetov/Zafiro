<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ResolveClubInstallationKeyRequest;
use App\Services\ClubInstallationKey;
use Illuminate\Http\JsonResponse;

class ClubInstallationController extends Controller
{
    public function __invoke(ResolveClubInstallationKeyRequest $request, ClubInstallationKey $keys): JsonResponse
    {
        $club = $keys->resolve($request->validated('club_key'));

        if ($club === null) {
            return response()->json(['message' => 'Ключ установки недействителен.'], 401);
        }

        return response()->json([
            'club_id' => $club->getKey(),
            'club_name' => $club->name,
            'api_base_url' => $request->root().'/api',
            'login_endpoint' => route('api.auth.login'),
        ]);
    }
}
