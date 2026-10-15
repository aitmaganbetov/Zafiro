<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubComputer;
use App\Services\ClubComputerSessions;
use App\Services\GuestFeatures;
use App\Services\SeatTransfers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer moves the rest of their session to another free PC (PC agent). */
class GuestSeatController extends Controller
{
    public function __construct(private SeatTransfers $transfers, private GuestFeatures $features) {}

    public function index(Request $request): JsonResponse
    {
        $this->features->ensure('self_transfer');
        $from = ClubComputer::query()->findOrFail((int) $request->validate(['computer_id' => ['required', 'integer']])['computer_id']);

        return response()->json(['data' => $this->transfers->freeSeatsFor($request->user(), $from)->map(fn (ClubComputer $computer): array => [
            'id' => $computer->id,
            'number' => $computer->number,
            'name' => $computer->displayName(),
            'zone' => $computer->zone?->name,
        ])]);
    }

    public function store(Request $request, ClubComputerSessions $sessions): JsonResponse
    {
        $this->features->ensure('self_transfer');
        $data = $request->validate(['computer_id' => ['required', 'integer'], 'target_computer_id' => ['required', 'integer']]);
        $from = ClubComputer::query()->findOrFail((int) $data['computer_id']);

        // As with buying time: only the agent of the PC the customer sits at may move their session.
        if ($from->agent_token_hash !== null
            && ! hash_equals($from->agent_token_hash, hash('sha256', (string) $request->header('X-Agent-Token')))) {
            return response()->json(['message' => __('Пересесть можно только с самого компьютера.'), 'code' => 'wrong_computer'], 403);
        }

        $target = $this->transfers->transfer($request->user(), $from, (int) $data['target_computer_id']);

        return response()->json([
            'message' => __('Сессия перенесена на ПК :computer. Войдите там своей учётной записью.', ['computer' => $target->displayName()]),
            'computer' => ['id' => $target->id, 'number' => $target->number, 'name' => $target->displayName()],
            'session' => $sessions->sessionPayload($target),
        ]);
    }
}
