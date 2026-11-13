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
            'bookings' => $this->bookings->presentAll($list),
        ]);
    }

    /** The club's PCs by zone: the guest picks one first. */
    public function computers(Request $request): JsonResponse
    {
        $this->bookings->ensureEnabled();

        return response()->json(['zones' => $this->bookings->computers($request->user())]);
    }

    /** Tariffs the guest may book for the start (valid in the zones of the picked PCs). */
    public function tariffs(Request $request): JsonResponse
    {
        $this->bookings->ensureEnabled();
        $startsAt = $this->start($request);
        $data = $request->validate(['computer_id' => ['nullable', 'integer'], 'computer_ids' => ['nullable', 'array', 'max:'.GuestBookings::MAX_COMPUTERS], 'computer_ids.*' => ['integer']]);
        $computerIds = array_map('intval', $data['computer_ids'] ?? array_filter([$data['computer_id'] ?? null]));

        return response()->json(['tariffs' => $this->bookings->tariffs($request->user(), $startsAt, $computerIds)]);
    }

    /** Zones and PCs free for the period (by the hour or a tariff), with the price. */
    public function options(Request $request): JsonResponse
    {
        $this->bookings->ensureEnabled();
        [$startsAt, $minutes, $tariffId] = $this->period($request);
        $plan = $this->bookings->plan($request->user(), $startsAt, $minutes, $tariffId);

        return response()->json(['minutes' => $plan['minutes'], 'zones' => $this->bookings->options($request->user(), $startsAt, $plan)]);
    }

    /** One PC (club_computer_id, older apps) or several booked together (club_computer_ids). */
    public function store(Request $request): JsonResponse
    {
        $this->bookings->ensureEnabled();
        $data = $request->validate([
            'club_computer_ids' => ['required_without:club_computer_id', 'array', 'min:1', 'max:'.GuestBookings::MAX_COMPUTERS],
            'club_computer_ids.*' => ['integer'],
            'club_computer_id' => ['required_without:club_computer_ids', 'integer'],
        ]);
        $computerIds = array_map('intval', $data['club_computer_ids'] ?? [$data['club_computer_id']]);
        [$startsAt, $minutes, $tariffId] = $this->period($request);
        $group = $this->bookings->book($request->user(), $computerIds, $startsAt, $minutes, $tariffId);
        $group->load(['computer:id,club_zone_id,number,display_name,device_name', 'computer.zone:id,name']);

        return response()->json([
            'message' => count($computerIds) > 1
                ? __('ПК забронированы. Код брони :code: на забронированных ПК его вводят на экране входа — оплаченное время начнётся само.', ['code' => $group->first()->code])
                : __('ПК забронирован. Войдите на нём в своё время — оплаченное время начнётся само.'),
            'booking' => $this->bookings->present($group),
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
