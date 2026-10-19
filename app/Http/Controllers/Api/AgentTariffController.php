<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseAgentTariffRequest;
use App\Models\ClubComputer;
use App\Models\Tariff;
use App\Services\AgentTariffPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentTariffController extends Controller
{
    public function index(Request $request, AgentTariffPurchaseService $tariffs): JsonResponse
    {
        return response()->json(['data' => $tariffs->availableFor($request->user())]);
    }

    public function purchase(PurchaseAgentTariffRequest $request, Tariff $tariff, AgentTariffPurchaseService $purchases): JsonResponse
    {
        // A PC with a device token sells time only to the person sitting at it: the request
        // must come from that PC's agent, so nobody can occupy another free PC remotely.
        $computer = ClubComputer::query()->findOrFail((int) $request->validated('computer_id'));
        if ($computer->agent_token_hash !== null
            && ! hash_equals($computer->agent_token_hash, hash('sha256', (string) $request->header('X-Agent-Token')))) {
            return response()->json(['message' => __('Тариф можно купить только на самом компьютере.'), 'code' => 'wrong_computer'], 403);
        }

        return response()->json($purchases->purchase(
            $request->user(),
            $tariff,
            (int) $request->validated('computer_id'),
            $request->validated('balance'),
        ));
    }
}
