<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseAgentTariffRequest;
use App\Models\Tariff;
use App\Services\AgentTariffPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AgentTariffController extends Controller
{
    public function index(Request $request, AgentTariffPurchaseService $tariffs): JsonResponse
    {
        return response()->json(['data' => $tariffs->availableFor($request->user())]);
    }

    public function purchase(PurchaseAgentTariffRequest $request, Tariff $tariff, AgentTariffPurchaseService $purchases): JsonResponse
    {
        $sessionId = $request->validated('session_id');
        if (! hash_equals((string) $request->bearerToken(), $sessionId)) {
            throw ValidationException::withMessages(['session_id' => __('На компьютере нет вашей активной сессии.')]);
        }

        return response()->json($purchases->purchase(
            $request->user(),
            $tariff,
            (int) $request->validated('computer_id'),
            $sessionId,
        ));
    }
}