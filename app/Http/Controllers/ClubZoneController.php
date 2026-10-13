<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClubZoneRequest;
use App\Http\Requests\UpdateClubZoneLayoutRequest;
use App\Http\Requests\UpdateClubZoneRequest;
use App\Models\ClubZone;
use App\Models\Tariff;
use App\Services\ActivityLogger;
use App\Services\ClubZoneLayoutService;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ClubZoneController extends Controller
{
    public function __construct(
        private ClubZoneLayoutService $layout,
        private CurrentClub $currentClub,
        private ActivityLogger $activity,
    ) {}

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
        DB::transaction(function () use ($data, $offset, $tariffIds, $request): void {
            $zone = ClubZone::query()->create($data + [
                'position_x' => 20 + ($offset % 240),
                'position_y' => 20 + ($offset % 180),
            ]);
            $zone->tariffs()->syncWithPivotValues($tariffIds, ['club_id' => $this->currentClub->id()]);
            $this->activity->record('zone.created', __('Создана зона: :zone', ['zone' => $zone->name]), $request->user()->getKey(), $zone, [
                'capacity' => $zone->capacity ?? '—',
                'icon' => $zone->icon,
                'tariff_ids' => $tariffIds === [] ? '—' : implode(', ', $tariffIds),
            ]);
        });

        return back()->with('status', __('Зона добавлена.'))->with('edit_mode', $request->boolean('edit_mode'));
    }

    public function update(UpdateClubZoneRequest $request, ClubZone $clubMap): RedirectResponse
    {
        $data = $request->validated();
        $tariffIds = $data['tariff_ids'] ?? [];
        unset($data['tariff_ids']);
        DB::transaction(function () use ($data, $tariffIds, $clubMap, $request): void {
            $original = $clubMap->getOriginal();
            $clubMap->update($data);
            $tariffChanges = $clubMap->tariffs()->syncWithPivotValues($tariffIds, ['club_id' => $this->currentClub->id()]);
            $changes = collect($clubMap->getChanges())
                ->except('updated_at')
                ->map(fn (mixed $value, string $field): string => ($original[$field] ?? '—').' → '.($value ?? '—'))
                ->all();

            if ($tariffChanges['attached'] !== [] || $tariffChanges['detached'] !== []) {
                $changes['tariff_ids'] = implode(', ', $tariffIds) ?: '—';
            }

            if ($changes !== []) {
                $this->activity->record('zone.updated', __('Изменена зона: :zone', ['zone' => $clubMap->name]), $request->user()->getKey(), $clubMap, $changes);
            }
        });

        return back()->with('status', __('Зона обновлена.'))->with('edit_mode', $request->boolean('edit_mode'));
    }

    public function destroy(Request $request, ClubZone $clubMap): RedirectResponse
    {
        DB::transaction(function () use ($request, $clubMap): void {
            $computerCount = $clubMap->computers()->count();
            $clubMap->delete();
            $this->activity->record('zone.deleted', __('Удалена зона: :zone', ['zone' => $clubMap->name]), $request->user()->getKey(), $clubMap, [
                'computers_removed' => $computerCount,
            ]);
        });

        return back()->with('status', __('Зона удалена.'));
    }

    public function updateLayout(UpdateClubZoneLayoutRequest $request, ClubZone $clubMap): JsonResponse
    {
        $layout = $request->validated();
        $gap = 20;
        $overlaps = ClubZone::query()
            ->whereKeyNot($clubMap->getKey())
            ->get(['position_x', 'position_y', 'width', 'height'])
            ->contains(fn (ClubZone $zone): bool => $layout['position_x'] < $zone->position_x + $zone->width + $gap
                && $zone->position_x < $layout['position_x'] + $layout['width'] + $gap
                && $layout['position_y'] < $zone->position_y + $zone->height + $gap
                && $zone->position_y < $layout['position_y'] + $layout['height'] + $gap);

        if ($overlaps) {
            return response()->json(['message' => __('Карточки залов не могут пересекаться.')], 409);
        }

        $clubMap->update($layout);

        return response()->json(['saved' => true]);
    }

    public function align(): RedirectResponse
    {
        $this->layout->align();

        return back()->with('status', __('Карточки выровнены по сетке с одинаковыми отступами.'));
    }
}
