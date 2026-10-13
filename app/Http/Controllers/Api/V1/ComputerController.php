<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\Integrations\WebhookDispatcher;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ComputerController extends ApiV1Controller
{
    public function zones(Request $request): JsonResponse
    {
        return $this->paginate($request, ClubZone::query()->withCount('computers'), Transformers::zone(...));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['zone_id' => ['nullable', 'integer']]);
        $query = ClubComputer::query()->when($filters['zone_id'] ?? null, fn ($q, int $id) => $q->where('club_zone_id', $id));

        return $this->paginate($request, $query, Transformers::computer(...));
    }

    public function show(ClubComputer $computer): JsonResponse
    {
        return response()->json(['data' => Transformers::computer($computer)]);
    }

    /** Booking: reserve or release one PC. Setting the current state again is a no-op. */
    public function reservation(Request $request, ClubComputer $computer, ActivityLogger $activity, WebhookDispatcher $webhooks): JsonResponse
    {
        $reserved = (bool) $request->validate(['reserved' => ['required', 'boolean']])['reserved'];

        $computer = DB::transaction(function () use ($computer, $reserved, $activity, $request, $webhooks): ClubComputer {
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($computer->id);

            if ($computer->is_reserved === $reserved) {
                return $computer;
            }

            if ($reserved && $computer->current_session_id !== null) {
                throw new ApiError('conflict', 409, 'The computer has an active session and cannot be reserved.');
            }

            $computer->update(['is_reserved' => $reserved]);
            $action = $reserved ? 'reserve' : 'unreserve';
            $activity->record('computer.controlled', __('Управление ПК (:action): :names', ['action' => $action, 'names' => $computer->displayName()]), $this->actorId($request), $computer, [
                'action' => $action,
                'computers' => $computer->displayName(),
                'updated' => 1,
            ]);
            $webhooks->dispatch('computer.reservation_changed', ['computer_ids' => [$computer->id], 'reserved' => $reserved]);

            return $computer;
        });

        return response()->json(['data' => Transformers::computer($computer)]);
    }

    /**
     * Power commands for up to integrations.max_bulk_items PCs of the key's club.
     * PCs with an active customer session are refused as a whole batch.
     */
    public function control(Request $request, ActivityLogger $activity, WebhookDispatcher $webhooks): JsonResponse
    {
        $data = $request->validate([
            'computer_ids' => ['required', 'array', 'min:1', 'max:'.(int) config('integrations.max_bulk_items', 50)],
            'computer_ids.*' => ['required', 'integer', 'distinct', Rule::exists((new ClubComputer)->getTable(), 'id')->where('club_id', app(CurrentClub::class)->id())],
            'action' => ['required', Rule::in(['power_on', 'restart', 'power_off'])],
        ]);
        $status = ['power_on' => 'online', 'restart' => 'restarting', 'power_off' => 'offline'][$data['action']];

        $computers = DB::transaction(function () use ($data, $status, $activity, $request, $webhooks) {
            $computers = ClubComputer::query()->whereKey($data['computer_ids'])->orderBy('number')->lockForUpdate()->get();
            $busy = $data['action'] === 'power_on' ? collect() : $computers->whereNotNull('current_session_id');

            if ($busy->isNotEmpty()) {
                throw new ApiError('conflict', 409, 'Some computers have an active customer session.', ['computer_ids' => $busy->pluck('id')->values()->all()]);
            }

            ClubComputer::query()->whereKey($computers->modelKeys())->update(['status' => $status]);
            $names = $computers->map(fn (ClubComputer $computer): string => $computer->displayName())->implode(', ');
            $activity->record('computer.controlled', __('Управление ПК (:action): :names', ['action' => $data['action'], 'names' => $names]), $this->actorId($request), null, [
                'action' => $data['action'],
                'computers' => $names,
                'updated' => $computers->count(),
            ]);
            $webhooks->dispatch('computer.controlled', ['computer_ids' => $computers->modelKeys(), 'action' => $data['action'], 'status' => $status]);

            return ClubComputer::query()->whereKey($computers->modelKeys())->orderBy('number')->get();
        });

        return response()->json(['data' => [
            'action' => $data['action'],
            'updated' => $computers->count(),
            'computers' => $computers->map(Transformers::computer(...))->values(),
        ]]);
    }
}
