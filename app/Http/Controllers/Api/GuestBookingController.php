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
            ->with(['computer:id,club_zone_id,number,display_name,device_name', 'computer.zone:id,name', 'tariff:id,name'])
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

    /** Tariffs the guest may book for the start. */
    public function tariffs(Request $request): JsonResponse
    {
        $this->bookings->ensureEnabled();
        $startsAt = $this->start($request);

        return response()->json(['tariffs' => $this->bookings->tariffs($request->user(), $startsAt)]);
    }

    /** Zones and PCs free for the period (by the hour or a tariff), with the price. */
    public function options(Request $request): JsonResponse
    {
        $this->bookings->ensureEnabled();
        [$startsAt, $minutes, $tariffId] = $this->period($request);
        $plan = $this->bookings->plan($request->user(), $startsAt, $minutes, $tariffId);

        return response()->json(['minutes' => $plan['minutes'], 'zones' => $this->bookings->options($request->user(), $startsAt, $plan)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->bookings->ensureEnabled();
        $computerId = (int) $request->validate(['club_computer_id' => ['required', 'integer']])['club_computer_id'];
        [$startsAt, $minutes, $tariffId] = $this->period($request);
        $reservation = $this->bookings->book($request->user(), $computerId, $startsAt, $minutes, $tariffId);
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

    /** The start, to the minute. */
    private function start(Request $request): Carbon
    {
        return Carbon::parse($request->validate(['starts_at' => ['required', 'date']])['starts_at'])->utc()->startOfMinute();
    }

    /** @return array{0: Carbon, 1: ?int, 2: ?int} the start, and the duration by the hour or the tariff */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'tariff_id' => ['nullable', 'integer'],
            'duration_minutes' => ['required_without:tariff_id', 'nullable', 'integer', 'min:'.GuestBookings::MIN_MINUTES, 'max:'.GuestBookings::MAX_MINUTES],
        ]);
        $tariffId = isset($data['tariff_id']) ? (int) $data['tariff_id'] : null;

        return [$this->start($request), $tariffId === null ? (int) $data['duration_minutes'] : null, $tariffId];
    }
}
