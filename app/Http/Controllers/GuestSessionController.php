<?php

namespace App\Http\Controllers;

use App\Services\CurrentClub;
use App\Services\GuestFeatures;
use App\Services\GuestSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** "Start tariff" on the club map: time for a guest without an account, paid at the cash register. */
class GuestSessionController extends Controller
{
    public function store(Request $request, GuestSessions $sessions, CurrentClub $currentClub, GuestFeatures $features): JsonResponse
    {
        if (! $features->guestModeEnabled()) {
            return response()->json(['message' => __('Гостевой режим отключён в настройках клуба.')], 403);
        }

        // A panel route: errors go back as JSON for the map's dialog, not as a redirect.
        try {
            return $this->sell($request, $sessions, $currentClub);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }
    }

    private function sell(Request $request, GuestSessions $sessions, CurrentClub $currentClub): JsonResponse
    {
        $data = $request->validate([
            'computer_ids' => ['required', 'array', 'min:1', 'max:50'],
            'computer_ids.*' => ['required', 'integer', 'distinct', Rule::exists('club_computers', 'id')->where('club_id', $currentClub->id())],
            'tariff_id' => ['nullable', 'integer', 'required_without:minutes'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:720', 'required_without:tariff_id'],
            'payment_method' => ['required', Rule::in(GuestSessions::PAYMENT_METHODS)],
        ]);

        $result = $sessions->sell(
            array_map('intval', $data['computer_ids']),
            isset($data['tariff_id']) ? (int) $data['tariff_id'] : null,
            isset($data['tariff_id']) ? null : (int) $data['minutes'],
            $data['payment_method'],
            $request->user()->getKey(),
        );

        return response()->json([
            'message' => __('Тариф подключён: :count ПК, к оплате :amount.', ['count' => $result['computers']->count(), 'amount' => number_format((float) $result['amount'], 2, ',', ' ')]),
            'amount' => $result['amount'],
            'computers' => $result['computers']->map(fn ($computer) => ['id' => $computer->id, 'ends_at' => $computer->session_ends_at?->format('H:i')])->values(),
        ]);
    }
}
