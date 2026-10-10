<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClubZoneRequest;
use App\Http\Requests\UpdateClubZoneRequest;
use App\Http\Requests\UpdateClubZoneLayoutRequest;
use App\Models\ClubZone;
use App\Models\Tariff;
use App\Services\ClubZoneLayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ClubZoneController extends Controller
{
    public function __construct(private ClubZoneLayoutService $layout) {}

    public function index(): View
    {
        return view('club-map.index', [
            'zones' => ClubZone::query()->with(['computers', 'tariffs'])->orderBy('sort_order')->orderBy('id')->get(),
            'tariffs' => Tariff::query()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreClubZoneRequest $request): RedirectResponse
    {
        $offset = ClubZone::query()->count() * 30;
        $data = $request->validated();
        $tariffIds = $data['tariff_ids'] ?? [];
        unset($data['tariff_ids']);
        $zone = ClubZone::query()->create($data + [
            'position_x' => 20 + ($offset % 240),
            'position_y' => 20 + ($offset % 180),
        ]);
        $zone->tariffs()->sync($tariffIds);
        return back()->with('status', 'Зона добавлена.')->with('edit_mode', $request->boolean('edit_mode'));
    }

    public function update(UpdateClubZoneRequest $request, ClubZone $clubMap): RedirectResponse
    {
        $data = $request->validated();
        $tariffIds = $data['tariff_ids'] ?? [];
        unset($data['tariff_ids']);
        $clubMap->update($data);
        $clubMap->tariffs()->sync($tariffIds);
        return back()->with('status', 'Зона обновлена.')->with('edit_mode', $request->boolean('edit_mode'));
    }

    public function destroy(ClubZone $clubMap): RedirectResponse
    {
        $clubMap->delete();

        return back()->with('status', 'Зона удалена.');
    }

    public function updateLayout(UpdateClubZoneLayoutRequest $request, ClubZone $clubMap): JsonResponse
    {
        $layout = $request->validated();
        $gap = 20;
        $overlaps = ClubZone::query()
            ->whereKeyNot($clubMap->getKey())
            ->get(['position_x', 'position_y', 'width', 'height'])
            ->contains(fn (ClubZone $zone): bool => $layout['position_x'] < $zone->position_x + $zone->width + $gap
                && $layout['position_x'] + $layout['width'] + $gap > $zone->position_x
                && $layout['position_y'] < $zone->position_y + $zone->height + $gap
                && $layout['position_y'] + $layout['height'] + $gap > $zone->position_y);

        if ($overlaps) {
            return response()->json(['message' => 'Карточки залов не могут пересекаться.'], 409);
        }

        $clubMap->update($layout);

        return response()->json(['saved' => true]);
    }

    public function align(): RedirectResponse
    {
        $this->layout->align();

        return back()->with('status', 'Карточки выровнены по сетке с одинаковыми отступами.');
    }
}
