<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubPhoto;
use App\Models\ClubZone;
use App\Support\Cities;
use App\Support\ZoneDevices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The guest app before signing in: the clubs of the guest's city (the cities come with coordinates,
 * the app finds the nearest one on the phone) and the page of a club.
 */
class ClubCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $city = $request->validate(['city' => ['nullable', 'string', Rule::in(Cities::keys())]])['city'] ?? null;
        $counts = Club::query()->whereNotNull('city')->groupBy('city')->selectRaw('city, COUNT(*) as clubs')->pluck('clubs', 'city');

        $cities = collect(Cities::options())
            ->map(fn (string $name, string $key) => ['key' => $key, 'name' => $name, ...Cities::coordinates($key), 'clubs' => (int) ($counts[$key] ?? 0)])
            ->values();

        $clubs = $city === null ? collect() : Club::query()
            ->where('city', $city)
            ->withCount(['computers as pcs', 'computers as free_pcs' => fn (Builder $query) => $this->free($query)])
            ->withMin('zones', 'price_per_hour')
            ->with(['photos' => fn ($query) => $query->limit(1)])
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'address', 'logo_path', 'profile']);

        return response()->json(['cities' => $cities, 'city' => $city, 'clubs' => $clubs->map(fn (Club $club) => [
            'id' => $club->id,
            'name' => $club->name,
            'address' => $club->address,
            'logo_url' => $club->logoUrl(),
            'photo_url' => $club->photos->first()?->url(),
            'hours' => $club->profile['hours'] ?? null,
            'pcs' => (int) $club->pcs,
            'free_pcs' => (int) $club->free_pcs,
            'price_from' => $club->zones_min_price_per_hour === null ? null : (float) $club->zones_min_price_per_hour,
        ])->all()]);
    }

    public function show(int $club): JsonResponse
    {
        $club = Club::query()->whereNotNull('city')->findOrFail($club);
        $zones = ClubZone::query()->withoutGlobalScope('club')
            ->where('club_id', $club->id)
            ->withCount([
                'computers as pcs' => fn (Builder $query) => $query->withoutGlobalScope('club'),
                'computers as free_pcs' => fn (Builder $query) => $this->free($query->withoutGlobalScope('club')),
            ])
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'description', 'price_per_hour', 'devices']);
        $detected = ZoneDevices::detected($club->id, $zones->modelKeys());
        $labels = ZoneDevices::fields();
        $profile = $club->profile ?? [];

        return response()->json([
            'id' => $club->id,
            'name' => $club->name,
            'city' => Cities::name($club->city),
            'address' => $club->address,
            'logo_url' => $club->logoUrl(),
            'description' => $profile['description'] ?? null,
            'phone' => $profile['phone'] ?? null,
            'hours' => $profile['hours'] ?? null,
            'instagram' => $profile['instagram'] ?? null,
            'photos' => ClubPhoto::query()->withoutGlobalScope('club')->where('club_id', $club->id)->orderBy('sort_order')->orderBy('id')->get()->map->url()->all(),
            'zones' => $zones->map(fn (ClubZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'description' => $zone->description,
                'price_per_hour' => (float) $zone->price_per_hour,
                'pcs' => (int) $zone->pcs,
                'free_pcs' => (int) $zone->free_pcs,
                'devices' => collect($labels)
                    ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'value' => $zone->devices[$key] ?? $detected[$zone->id][$key] ?? null])
                    ->filter(fn (array $device) => $device['value'] !== null)
                    ->values()->all(),
            ])->all(),
        ]);
    }

    /** A PC a guest can sit at now: its agent is online, no session, no reservation, no staff on it. */
    private function free(Builder $query): void
    {
        $query->where('last_seen_at', '>=', now()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS))
            ->where('is_reserved', false)
            ->where(fn (Builder $session) => $session->whereNull('current_session_id')->orWhere('session_ends_at', '<=', now()))
            ->where(fn (Builder $staff) => $staff->whereNull('staff_until')->orWhere('staff_until', '<=', now()));
    }
}
