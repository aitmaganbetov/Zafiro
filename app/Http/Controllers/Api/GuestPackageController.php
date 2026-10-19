<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubComputer;
use App\Models\TimePackage;
use App\Services\AgentTariffPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer's kept tariff time (a tariff with a usage limit ended early), started again in the agent. */
class GuestPackageController extends Controller
{
    public function index(Request $request, AgentTariffPurchaseService $purchases): JsonResponse
    {
        return response()->json(['data' => $purchases->packagesFor($request->user())]);
    }

    public function start(Request $request, TimePackage $package, AgentTariffPurchaseService $purchases): JsonResponse
    {
        $data = $request->validate(['computer_id' => ['required', 'integer']]);
        $computer = ClubComputer::query()->findOrFail((int) $data['computer_id']);
        // As with tariffs: only the agent of the PC the customer sits at may start time on it.
        if ($computer->agent_token_hash !== null
            && ! hash_equals($computer->agent_token_hash, hash('sha256', (string) $request->header('X-Agent-Token')))) {
            return response()->json(['message' => __('Тариф можно купить только на самом компьютере.'), 'code' => 'wrong_computer'], 403);
        }

        return response()->json($purchases->startPackage($request->user(), $package, $computer->getKey()));
    }
}
