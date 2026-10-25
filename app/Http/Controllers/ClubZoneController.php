<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClubZoneRequest;
use App\Http\Requests\UpdateClubZoneLayoutRequest;
use App\Http\Requests\UpdateClubZoneRequest;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\SessionPurchase;
use App\Models\Tariff;
use App\Services\ActivityLogger;
use App\Services\AgentUpdate;
use App\Services\ClubZoneLayoutService;
use App\Services\ComputerReservations;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ClubZoneController extends Controller
{
    public function __construct(
        private ClubZoneLayoutService $layout,
        private CurrentClub $currentClub,
        private ActivityLogger $activity,
    ) {}

    public function index(ComputerReservations $reservations, AgentUpdate $agentUpdate): View
    {
        $zones = ClubZone::query()->with(['computers.staffUser:id,name', 'computers.sessionUser:id,name,phone', 'tariffs'])->orderBy('sort_order')->orderBy('id')->get();

        return view('club-map.index', [
            'zones' => $zones,
            'states' => $this->computerStates($zones->pluck('computers')->flatten(), $reservations, $agentUpdate),
            'tariffs' => Tariff::query()->orderBy('name')->get(),
            'agentVersions' => $agentUpdate,
            'latestAgent' => $agentUpdate->latestVersion(),
        ]);
    }

    /** The PCs as the map shows them now: the open map asks for this every few seconds. */
    public function state(ComputerReservations $reservations, AgentUpdate $agentUpdate): JsonResponse
    {
        $computers = ClubComputer::query()->with(['staffUser:id,name', 'sessionUser:id,name,phone'])->get();

        return response()->json(['computers' => $this->computerStates($computers, $reservations, $agentUpdate)]);
    }

    /**
     * What the map shows of each PC: status, glow (staff, a session of an account or a guest, on
     * and free), the session and who sits there.
     *
     * @param  SupportCollection<int, ClubComputer>  $computers
     * @return array<int, array<string, mixed>> PC id => state
     */
    private function computerStates(SupportCollection $computers, ComputerReservations $reservations, AgentUpdate $agentUpdate): array
    {
        // What the customer bought for the running sessions: tariffs and per-minute time, oldest first.
        $sessionIds = $computers->pluck('current_session_id')->filter()->values();
        $purchases = $sessionIds->isEmpty() ? collect() : SessionPurchase::query()
            ->whereIn('session_id', $sessionIds)->orderBy('id')
            ->get(['session_id', 'kind', 'name', 'minutes'])
            ->groupBy('session_id')
            ->map(fn ($parts) => $parts->map(fn (SessionPurchase $part): string => $part->kind === SessionPurchase::KIND_TARIFF
                ? $part->name
                : __('Поминутно: :count мин.', ['count' => $part->minutes]))->implode(', '));
        $booked = $reservations->heldComputerIds();
        $latest = $agentUpdate->latestVersion();

        $states = [];
        foreach ($computers as $computer) {
            $computer->bookedNow = in_array($computer->id, $booked, true);
            $status = $computer->currentStatus();
            $staff = $computer->hasStaffVisit();
            $session = $computer->current_session_id !== null;
            $light = $staff ? 'pc-staff' : ($computer->hasPaidSession() ? ($computer->session_guest ? 'pc-guest' : 'pc-account') : ($status === 'online' ? 'pc-free' : ''));
            $states[$computer->id] = [
                'classes' => trim($status.' '.$light),
                'status' => $status,
                'connection' => $computer->connectionStatus(),
                'agent_state' => $computer->agent_state ?? '',
                'has_session' => $session,
                'session_ends_at' => $computer->session_ends_at?->toIso8601String(),
                'session_user' => ! $session ? '' : ($computer->session_guest ? __('Гость без аккаунта') : trim(($computer->sessionUser?->name ?? '').' · '.($computer->sessionUser?->phone ?? ''), ' ·')),
                'session_tariff' => ! $session ? '' : ($purchases[$computer->current_session_id] ?? ($computer->session_guest ? __('Оплачено на кассе') : '')),
                'is_reserved' => (bool) $computer->is_reserved,
                'last_seen' => $computer->last_seen_at?->toIso8601String(),
                'staff_until' => $staff ? $computer->staff_until->toIso8601String() : '',
                'staff_name' => $staff ? (string) $computer->staffUser?->name : '',
                'staff_kind' => $staff ? (string) $computer->staff_kind : '',
                'agent_version' => (string) $computer->agent_version,
                'agent_outdated' => $agentUpdate->isOutdated($computer->agent_version, $latest),
            ];
        }

        return $states;
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
