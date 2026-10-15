<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GuestHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in customer's log in the PC agent (newest first, by pages). */
class GuestHistoryController extends Controller
{
    public function __invoke(Request $request, GuestHistory $history): JsonResponse
    {
        $before = $request->validate(['before' => ['nullable', 'integer', 'min:1']])['before'] ?? null;

        return response()->json($history->page($request->user(), $before === null ? null : (int) $before));
    }
}
