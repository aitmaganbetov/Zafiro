<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Support\Cities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The guest app opens on the clubs of the guest's city, before signing in: the cities with their
 * coordinates (the app finds the nearest one on the phone) and the clubs of the chosen city.
 */
class ClubCatalogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $city = $request->validate(['city' => ['nullable', 'string', Rule::in(Cities::keys())]])['city'] ?? null;
        $counts = Club::query()->whereNotNull('city')->groupBy('city')->selectRaw('city, COUNT(*) as clubs')->pluck('clubs', 'city');

        $cities = collect(Cities::options())
            ->map(fn (string $name, string $key) => ['key' => $key, 'name' => $name, ...Cities::coordinates($key), 'clubs' => (int) ($counts[$key] ?? 0)])
            ->values();

        $clubs = $city === null ? [] : Club::query()
            ->where('city', $city)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'address', 'logo_path'])
            ->map(fn (Club $club) => ['id' => $club->id, 'name' => $club->name, 'address' => $club->address, 'logo_url' => $club->logoUrl()])
            ->all();

        return response()->json(['cities' => $cities, 'city' => $city, 'clubs' => $clubs]);
    }
}
