<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Checks the staff PIN typed on a PC (Ctrl+Alt+P) before the agent may close.
 * Authenticated by the PC's device token and limited to a few attempts.
 */
class AgentExitPinController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'string', 'max:16']]);
        $computer = $request->attributes->get('agent_computer');
        $hash = Club::query()->whereKey($computer->club_id)->value('agent_exit_pin_hash');

        if ($hash === null) {
            return response()->json(['valid' => false, 'code' => 'pin_not_configured', 'message' => __('PIN-код не задан. Задайте его в настройках клуба в панели Zafiro.')], 409);
        }

        if (! Hash::check($data['pin'], $hash)) {
            Log::warning('Wrong agent exit PIN', ['club_id' => $computer->club_id, 'computer_id' => $computer->getKey(), 'ip' => $request->ip()]);

            return response()->json(['valid' => false, 'code' => 'invalid_pin', 'message' => __('Неверный PIN-код.')], 422);
        }

        Log::info('Agent closed with staff PIN', ['club_id' => $computer->club_id, 'computer_id' => $computer->getKey(), 'ip' => $request->ip()]);

        return response()->json(['valid' => true]);
    }
}
