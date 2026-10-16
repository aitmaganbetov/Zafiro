<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComputerReservationRequest;
use App\Models\ClubComputer;
use App\Models\ComputerReservation;
use App\Models\User;
use App\Services\ComputerReservations;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ComputerReservationController extends Controller
{
    public function __construct(private ComputerReservations $reservations, private CurrentClub $currentClub) {}

    /** Bookings of one day (the club's local day), by start time. */
    public function index(Request $request): View
    {
        $timezone = $this->currentClub->timezone();
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;
        $day = $date === null ? Carbon::now($timezone)->startOfDay() : Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();

        $reservations = ComputerReservation::query()
            ->with(['computer:id,number,display_name,device_name,club_zone_id', 'computer.zone:id,name', 'user:id,name,phone'])
            ->where('starts_at', '>=', $day->copy()->setTimezone(config('app.timezone')))
            ->where('starts_at', '<', $day->copy()->addDay()->setTimezone(config('app.timezone')))
            ->orderBy('starts_at')
            ->paginate(50)
            ->withQueryString();

        return view('reservations.index', [
            'reservations' => $reservations,
            'day' => $day,
            'computers' => ClubComputer::query()->with('zone:id,name')->orderBy('club_zone_id')->orderBy('number')
                ->get(['id', 'number', 'display_name', 'device_name', 'club_zone_id']),
            'gapMinutes' => $this->reservations->gapMinutes(),
            'defaultStart' => Carbon::now($timezone)->addHour()->startOfHour(),
        ]);
    }

    public function store(StoreComputerReservationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $startsAt = Carbon::createFromFormat('Y-m-d\TH:i', $data['starts_at'], $this->currentClub->timezone());
        if ($startsAt->lt(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['starts_at' => __('Нельзя забронировать ПК на прошедшее время.')]);
        }

        // Name and phone are kept with the booking even if the account is removed later.
        $customer = User::query()->findOrFail((int) $data['user_id'], ['id', 'name', 'phone']);

        $reservation = $this->reservations->create([
            'club_computer_id' => (int) $data['club_computer_id'],
            'user_id' => $customer->getKey(),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'note' => $data['note'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes((int) $data['duration_minutes']),
        ], $request->user());

        return redirect()->route('reservations.index', ['date' => $reservation->starts_at->format('Y-m-d')])
            ->with('status', __('ПК забронирован для клиента :name: в это время он сможет купить тариф на этом ПК.', ['name' => $customer->name]));
    }

    /**
     * Booking from the club map: the selected PCs for one customer and one period, all or none.
     * Errors go back as JSON for the map's dialog (a panel route would redirect).
     */
    public function storeMany(Request $request): JsonResponse
    {
        try {
            $clubId = $this->currentClub->id();
            $data = $request->validate([
                'computer_ids' => ['required', 'array', 'min:1', 'max:50'],
                'computer_ids.*' => ['required', 'integer', 'distinct', Rule::exists('club_computers', 'id')->where('club_id', $clubId)],
                'user_id' => ['required', 'integer', Rule::exists('club_memberships', 'user_id')->where('club_id', $clubId)],
                'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
                'duration_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
                'note' => ['nullable', 'string', 'max:500'],
            ], ['user_id.required' => __('Выберите клиента из пользователей клуба.'), 'user_id.exists' => __('Выберите клиента из пользователей клуба.')]);
            $startsAt = Carbon::createFromFormat('Y-m-d\TH:i', $data['starts_at'], $this->currentClub->timezone());
            if ($startsAt->lt(now()->subMinutes(5))) {
                throw ValidationException::withMessages(['starts_at' => __('Нельзя забронировать ПК на прошедшее время.')]);
            }
            $customer = User::query()->findOrFail((int) $data['user_id'], ['id', 'name', 'phone']);

            $count = DB::transaction(function () use ($data, $customer, $startsAt, $request): int {
                foreach ($data['computer_ids'] as $computerId) {
                    $this->reservations->create([
                        'club_computer_id' => (int) $computerId,
                        'user_id' => $customer->getKey(),
                        'customer_name' => $customer->name,
                        'customer_phone' => $customer->phone,
                        'note' => $data['note'] ?? null,
                        'starts_at' => $startsAt->copy(),
                        'ends_at' => $startsAt->copy()->addMinutes((int) $data['duration_minutes']),
                    ], $request->user());
                }

                return count($data['computer_ids']);
            });
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }

        return response()->json(['message' => __('Забронировано ПК: :count, для :name на :from – :to.', [
            'count' => $count,
            'name' => $customer->name,
            'from' => $startsAt->format('d.m H:i'),
            'to' => $startsAt->copy()->addMinutes((int) $data['duration_minutes'])->format('H:i'),
        ])], 201);
    }

    /** Club users for the customer picker, by name or phone (at most 10). */
    public function customers(Request $request): JsonResponse
    {
        $query = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:50']])['q'] ?? ''));
        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }
        // Phones are stored as +7XXXXXXXXXX: a number typed from 8 is searched from 7.
        $digits = preg_replace('/^8/', '7', preg_replace('/\D+/', '', $query));
        $like = fn (string $value): string => '%'.addcslashes($value, '\\%_').'%';

        $customers = User::query()
            ->whereHas('memberships', fn ($memberships) => $memberships->where('club_id', $this->currentClub->id()))
            ->where(function ($users) use ($query, $digits, $like): void {
                $users->where('name', 'like', $like($query));
                if (strlen($digits) >= 3) {
                    $users->orWhere('phone', 'like', $like($digits));
                }
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'phone']);

        return response()->json(['data' => $customers->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone])]);
    }

    public function cancel(Request $request, ComputerReservation $reservation): RedirectResponse
    {
        $this->reservations->cancel($reservation, $request->user());

        return back()->with('status', __('Бронь отменена.'));
    }

    public function arrive(Request $request, ComputerReservation $reservation): RedirectResponse
    {
        $this->reservations->arrive($reservation, $request->user());

        return back()->with('status', __('Отмечено: клиент пришёл. ПК свободен для его сессии.'));
    }
}
