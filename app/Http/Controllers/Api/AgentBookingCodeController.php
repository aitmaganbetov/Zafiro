<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ClubComputerSessions;
use App\Services\ComputerReservations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The sign-in screen of a PC takes the code of a booking from the guest app: when this PC is one
 * of the booked ones and the booking is due, its paid time starts here without an account (the
 * agent opens the PC as for time sold at the cash register).
 */
class AgentBookingCodeController extends Controller
{
    public function __invoke(Request $request, ComputerReservations $reservations, ClubComputerSessions $sessions): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], ['code.regex' => __('Код брони — 6 цифр.')])['code'];
        $computer = $reservations->startByCode($request->attributes->get('agent_computer'), $code);

        return response()->json([
            'message' => __('Бронь открыта: приятной игры!'),
            'session' => $sessions->sessionPayload($computer),
        ]);
    }
}
