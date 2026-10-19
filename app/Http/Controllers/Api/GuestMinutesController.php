<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Services\AgentTariffPurchaseService;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Time by the minute at the hourly price of the PC's zone (PC agent). */
class GuestMinutesController extends Controller
{
    public const MAX_MINUTES = 720;

    public function show(Request $request, CurrentClub $currentClub, AgentTariffPurchaseService $purchases): JsonResponse
    {
        $computer = ClubComputer::query()->findOrFail((int) $request->validate(['computer_id' => ['required', 'integer']])['computer_id']);
        $zone = ClubZone::query()->find($computer->club_zone_id, ['id', 'name', 'price_per_hour']);

        return response()->json([
            'available' => $zone?->price_per_hour !== null,
            'zone' => $zone?->name,
            // With the customer's discount when the club applies it to hourly time.
            'price_per_hour' => $zone?->price_per_hour === null ? null : $purchases->hourlyPrice($request->user(), $zone),
            'original_price_per_hour' => $zone?->price_per_hour,
            'currency' => data_get($currentClub->settings(), 'finances.currency', 'KZT'),
            'min_minutes' => 1,
            'max_minutes' => self::MAX_MINUTES,
        ]);
    }

    public function purchase(Request $request, AgentTariffPurchaseService $purchases): JsonResponse
    {
        $data = $request->validate([
            'computer_id' => ['required', 'integer'],
            'minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_MINUTES],
            'balance' => ['nullable', Rule::in(AgentTariffPurchaseService::BALANCES)],
        ]);
        $computer = ClubComputer::query()->findOrFail((int) $data['computer_id']);

        // As with tariffs: only the agent of the PC the customer sits at may sell time on it.
        if ($computer->agent_token_hash !== null
            && ! hash_equals($computer->agent_token_hash, hash('sha256', (string) $request->header('X-Agent-Token')))) {
            return response()->json(['message' => __('Тариф можно купить только на самом компьютере.'), 'code' => 'wrong_computer'], 403);
        }

        return response()->json($purchases->purchaseMinutes($request->user(), $computer->getKey(), (int) $data['minutes'], $data['balance'] ?? null));
    }
}
