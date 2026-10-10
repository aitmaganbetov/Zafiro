<?php

namespace App\Http\Controllers;

use App\Http\Requests\ControlClubComputersRequest;
use App\Http\Requests\UpdateClubComputerLayoutRequest;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Services\ClubZoneComputerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ClubComputerController extends Controller
{
    public function store(ClubZone $clubMap, ClubZoneComputerService $computers): RedirectResponse
    {
        abort_unless($clubMap->icon === 'desktop', 404);

        $computer = $computers->add($clubMap);
        $status = $computer === null ? 'Достигнута вместимость зоны.' : 'Компьютер добавлен в зону.';

        return back()->with('status', $status)->with('edit_mode', true);
    }

    public function control(ControlClubComputersRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ($data['action'] === 'reserve') {
            $computers = ClubComputer::query()->whereKey($data['computer_ids'])->get();
            $reserved = $computers->contains(fn (ClubComputer $computer): bool => ! $computer->is_reserved);
            $updated = ClubComputer::query()->whereKey($data['computer_ids'])->update(['is_reserved' => $reserved]);
            $statuses = ClubComputer::query()->whereKey($data['computer_ids'])->get()
                ->map(fn (ClubComputer $computer): array => ['id' => $computer->id, 'status' => $computer->currentStatus()])
                ->values();

            return response()->json(['updated' => $updated, 'reserved' => $reserved, 'computers' => $statuses]);
        }

        $status = match ($data['action']) {
            'power_on' => 'online',
            'restart' => 'restarting',
            'power_off' => 'offline',
        };

        $updated = ClubComputer::query()->whereKey($data['computer_ids'])->update(['status' => $status]);

        return response()->json(['updated' => $updated, 'status' => $status]);
    }

    public function heartbeat(ClubComputer $clubComputer): JsonResponse
    {
        $clubComputer->update([
            'last_seen_at' => now(),
            'status' => 'online',
        ]);

        return response()->json([
            'computer_id' => $clubComputer->id,
            'status' => $clubComputer->currentStatus(),
            'last_seen_at' => $clubComputer->last_seen_at->toIso8601String(),
            'heartbeat_interval_seconds' => 30,
        ]);
    }

    public function __invoke(UpdateClubComputerLayoutRequest $request, ClubComputer $clubComputer): JsonResponse
    {
        $layout = $request->validated();
        $occupied = ClubComputer::query()
            ->where('club_zone_id', $clubComputer->club_zone_id)
            ->whereKeyNot($clubComputer->getKey())
            ->where('position_x', $layout['position_x'])
            ->where('position_y', $layout['position_y'])
            ->exists();

        if ($occupied) {
            return response()->json(['message' => 'Эта ячейка уже занята другим компьютером.'], 409);
        }

        $clubComputer->update($layout);

        return response()->json(['saved' => true]);
    }
}
