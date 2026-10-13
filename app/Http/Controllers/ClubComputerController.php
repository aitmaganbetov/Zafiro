<?php

namespace App\Http\Controllers;

use App\Http\Requests\ControlClubComputersRequest;
use App\Http\Requests\HeartbeatClubComputerRequest;
use App\Http\Requests\ResolveClubComputerRequest;
use App\Http\Requests\RegisterClubComputerRequest;
use App\Http\Requests\UpdateClubComputerLayoutRequest;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Services\ClubZoneComputerService;
use App\Services\ClubComputerResolver;
use App\Services\ClubComputerRegistrar;
use Illuminate\Support\Facades\Gate;
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

    public function resolve(ResolveClubComputerRequest $request, ClubComputerResolver $computers): JsonResponse
    {
        $computer = $computers->resolve(
            $request->validated('device_name'),
            $request->validated('device_id'),
        );

        if ($computer === null) {
            return response()->json([
                'code' => 'computer_not_found',
                'message' => 'Компьютер не найден в текущем клубе.',
            ], 404);
        }

        return response()->json($this->computerIdentity($computer));
    }

    public function register(RegisterClubComputerRequest $request, ClubComputerRegistrar $computers): JsonResponse
    {
        Gate::authorize('register', ClubComputer::class);
        $result = $computers->register($request->validated());

        return response()->json(
            $this->computerIdentity($result['computer']),
            $result['created'] ? 201 : 200,
        );
    }

    public function heartbeat(HeartbeatClubComputerRequest $request, string $computer): JsonResponse
    {
        $computer = ClubComputer::query()->withoutGlobalScope('club')->findOrFail($computer);
        Gate::authorize('heartbeat', $computer);

        $data = $request->validated();
        $updates = [
            'last_seen_at' => now(),
            'status' => 'online',
            'remote_ip' => $request->ip(),
        ];

        if (array_key_exists('state', $data)) {
            $updates['agent_state'] = $data['state'];
            $updates['current_session_id'] = $data['state'] === 'in_session'
                ? ($data['session_id'] ?? null)
                : null;
        }

        if (array_key_exists('agent_version', $data)) {
            $updates['agent_version'] = $data['agent_version'];
        }

        if (! empty($data['device_name'])) {
            $updates['device_name'] = $data['device_name'];
        }

        $hardwareFields = ['mac_address', 'local_ip', 'cpu_model', 'cpu_cores', 'ram_total_mb', 'gpu_model', 'os_version'];
        foreach ($hardwareFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }
        if (array_intersect($hardwareFields, array_keys($data)) !== []) {
            $updates['hardware_reported_at'] = now();
        }

        $computer->update($updates);
        $computer->loadMissing('zone');
        $recommendedInterval = max(5, (int) config('club.computer_heartbeat_interval_seconds', 30));
        $connectionStatus = $computer->connectionStatus();

        return response()->json([
            ...$this->computerIdentity($computer),
            'status' => $data === [] ? $computer->currentStatus() : $connectionStatus,
            'connection_status' => $connectionStatus,
            'hardware' => [
                'mac_address' => $computer->mac_address,
                'local_ip' => $computer->local_ip,
                'remote_ip' => $computer->remote_ip,
                'cpu_model' => $computer->cpu_model,
                'cpu_cores' => $computer->cpu_cores,
                'ram_total_mb' => $computer->ram_total_mb,
                'gpu_model' => $computer->gpu_model,
                'os_version' => $computer->os_version,
                'reported_at' => $computer->hardware_reported_at?->utc()->toIso8601String(),
            ],
            'last_seen_at' => $computer->last_seen_at->utc()->toIso8601String(),
            'recommended_interval_seconds' => $recommendedInterval,
            'heartbeat_interval_seconds' => $recommendedInterval,
        ]);
    }

    /** @return array<string, mixed> */
    private function computerIdentity(ClubComputer $computer): array
    {
        $computer->loadMissing('zone');

        return [
            'computer_id' => $computer->getKey(),
            'number' => (string) $computer->number,
            'name' => $computer->displayName(),
            'zone' => [
                'id' => $computer->zone->getKey(),
                'name' => $computer->zone->name,
            ],
        ];
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
