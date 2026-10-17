<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubComputer;
use App\Services\AgentShop;
use App\Services\GuestFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The club shop in the PC agent: the customer pays for goods from the main balance. */
class GuestShopController extends Controller
{
    public function purchase(Request $request, GuestFeatures $features, AgentShop $shop): JsonResponse
    {
        if (! $features->shopEnabled()) {
            return response()->json(['message' => __('Магазин в агенте отключён в клубе.'), 'code' => 'shop_disabled'], 403);
        }

        $data = $request->validate([
            'computer_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:'.AgentShop::MAX_LINES],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.AgentShop::MAX_QUANTITY],
        ]);
        $computer = ClubComputer::query()->findOrFail((int) $data['computer_id']);

        // As with tariffs: only the agent of the PC the customer sits at may order to it.
        if ($computer->agent_token_hash !== null
            && ! hash_equals($computer->agent_token_hash, hash('sha256', (string) $request->header('X-Agent-Token')))) {
            return response()->json(['message' => __('Заказать товары можно только на самом компьютере.'), 'code' => 'wrong_computer'], 403);
        }

        $quantities = [];
        foreach ($data['items'] as $item) {
            $quantities[(int) $item['id']] = ($quantities[(int) $item['id']] ?? 0) + (int) $item['quantity'];
        }

        return response()->json($shop->purchase($request->user(), $computer, $quantities));
    }
}
