<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComputerReservation;
use App\Services\ComputerReservations;
use App\Services\CurrentClub;
use App\Services\GuestBookings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * PC bookings in the guest app (club settings → "Booking from the app"). Times come and go as
 * ISO 8601: the app shows them in the phone's time zone.
 */
class GuestBookingController extends Controller
{
    public function __construct(private GuestBookings $bookings) {}

    /** The guest's bookings ahead and what the club allows. */
    public function index(Request $request, ComputerReservations $reservations, CurrentClub $currentClub): JsonResponse
    {
        $list = ComputerReservation::query()
            ->with(['computer:id,club_zone_id,number,display_name,device_name', 'computer.zone:id,name'])
            ->where('user_id', $request->user()->getKey())
            ->where('starts_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->limit(20)
            ->get();

        return response()->json([
            'enabled' => $reservations->appBookingEnabled(),
            'can_cancel' => $reservations->guestMayCancel(),
            'gap_minutes' => $reservations->gapMinutes(),
            'currency' => data_get($currentClub->settings(), 'finances.currency', 'KZT'),
            'min_minutes' => GuestBookings::MIN_MINUTES,
            'max_minutes' => GuestBookings::MAX_MINUTES,
            'max_days_ahead' => GuestBookings::MAX_DAYS_AHEAD,
            'bookings' => $list->map(fn (ComputerReservation $reservation): array => $this->bookings->present($reservation))->values(),
        ]);
    }

    /** Zones and PCs free for the period, with the price. */
    public function options(Request $request): JsonResponse
    {
        $this->bookings->ensureEnabled();
        [$startsAt, $minutes] = $this->period($request);

        return response()->json(['zones' => $this->bookings->options($request->user(), $startsAt, $minutes)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->bookings->ensureEnabled();
        $computerId = (int) $request->validate(['club_computer_id' => ['required', 'integer']])['club_computer_id'];
        [$startsAt, $minutes] = $this->period($request);
        $reservation = $this->bookings->book($request->user(), $computerId, $startsAt, $minutes);
        $reservation->load(['computer:id,club_zone_id,number,display_name,device_name', 'computer.zone:id,name']);

        return response()->json([
            'message' => __('ПК забронирован. Войдите на нём в своё время — оплаченное время начнётся само.'),
            'booking' => $this->bookings->present($reservation),
        ], 201);
    }

    public function cancel(Request $request, int $reservation): JsonResponse
    {
        $this->bookings->cancel($request->user(), $reservation);

        return response()->json(['message' => __('Бронь отменена, деньги вернулись на баланс.')]);
    }

    /** @return array{0: Carbon, 1: int} the start (to the minute) and the duration */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:'.GuestBookings::MIN_MINUTES, 'max:'.GuestBookings::MAX_MINUTES],
        ]);

        return [Carbon::parse($data['starts_at'])->utc()->startOfMinute(), (int) $data['duration_minutes']];
    }
}
