<?php

namespace App\Http\Controllers\Api\Staff;

use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Tariff;
use App\Models\User;
use App\Services\AgentTariffPurchaseService;
use App\Services\AgentUpdate;
use App\Services\ComputerReservations;
use App\Services\GuestFeatures;
use Illuminate\Http\JsonResponse;

/** The club map of the staff app: PCs by zone with their state, and what a guest session may be sold for. */
class ComputerController extends StaffApiController
{
    public function index(ComputerReservations $reservations, GuestFeatures $features, AgentTariffPurchaseService $tariffs, AgentUpdate $agentUpdate): JsonResponse
    {
        $latestAgent = $agentUpdate->latestVersion();
        $held = $reservations->heldComputerIds();
        $computers = ClubComputer::query()->with('staffUser:id,name')->orderBy('club_zone_id')->orderBy('number')->get();
        $customers = User::query()->whereKey($computers->pluck('current_session_user_id')->filter()->unique()->all())->pluck('name', 'id');

        return response()->json([
            'zones' => ClubZone::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'price_per_hour'])
                ->map(fn (ClubZone $zone): array => ['id' => $zone->id, 'name' => $zone->name, 'price_per_hour' => $zone->price_per_hour === null ? null : self::money($zone->price_per_hour)])->all(),
            'computers' => $computers->map(function (ClubComputer $computer) use ($held, $customers, $agentUpdate, $latestAgent): array {
                $computer->bookedNow = in_array($computer->id, $held, true);
                $paid = $computer->hasPaidSession();

                return [
                    'id' => $computer->id,
                    'number' => $computer->number,
                    'name' => $computer->displayName(),
                    'zone_id' => $computer->club_zone_id,
                    'status' => $computer->currentStatus(),
                    'connection' => $computer->connectionStatus(),
                    'agent_state' => $computer->agent_state,
                    'agent_version' => $computer->agent_version,
                    'agent_outdated' => $agentUpdate->isOutdated($computer->agent_version, $latestAgent),
                    'reserved' => (bool) $computer->is_reserved,
                    'booked_now' => $computer->bookedNow,
                    'session' => ! $paid ? null : [
                        'id' => $computer->current_session_id,
                        'ends_at' => self::at($computer->session_ends_at),
                        'guest' => (bool) $computer->session_guest,
                        'user' => $computer->current_session_user_id === null ? null : [
                            'id' => $computer->current_session_user_id,
                            'name' => $customers[$computer->current_session_user_id] ?? null,
                        ],
                    ],
                    'staff_visit' => ! $computer->hasStaffVisit() ? null : [
                        'until' => self::at($computer->staff_until),
                        'name' => $computer->staffUser?->name,
                    ],
                ];
            })->all(),
            'guest_mode' => $features->guestModeEnabled(),
            'tariffs' => Tariff::query()->where('is_active', true)->with('zones:id')->orderBy('duration_minutes')->orderBy('name')->get()
                ->map(fn (Tariff $tariff): array => [
                    'id' => $tariff->id,
                    'name' => $tariff->name,
                    'minutes' => $tariffs->effectiveMinutes($tariff),
                    'price' => self::money($tariff->price),
                    'zone_ids' => $tariff->zones->modelKeys(),
                ])->all(),
        ]);
    }
}
