<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GuestHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The signed-in customer's log in the PC agent (newest first, by pages). */
class GuestHistoryController extends Controller
{
    public function __invoke(Request $request, GuestHistory $history): JsonResponse
    {
        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'filter' => ['nullable', 'string', Rule::in(array_keys(GuestHistory::FILTERS))],
        ]);

        return response()->json($history->page($request->user(), isset($data['before']) ? (int) $data['before'] : null, $data['filter'] ?? null));
    }
}
