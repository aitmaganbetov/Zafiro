<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTariffRequest;
use App\Http\Requests\UpdateTariffRequest;
use App\Models\ClubZone;
use App\Models\Tariff;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TariffController extends Controller
{
    private const DURATION_FACTORS = ['minutes' => 1, 'hours' => 60, 'days' => 1440];

    public function __construct(private CurrentClub $currentClub) {}

    public function index(): View
    {
        return $this->page(null);
    }

    /** The list with the tariff's form open, filled in. */
    public function edit(Tariff $tariff): View
    {
        return $this->page($tariff->load('zones'));
    }

    public function store(StoreTariffRequest $request, ActivityLogger $activity): RedirectResponse
    {
        [$data, $zoneIds] = $this->attributes($request->validated());
        $data['currency'] = data_get($this->currentClub->settings() ?: [], 'finances.currency', 'KZT');

        DB::transaction(function () use ($data, $zoneIds, $request, $activity): void {
            $tariff = Tariff::query()->create($data);
            $tariff->zones()->syncWithPivotValues($zoneIds, ['club_id' => $this->currentClub->id()]);
            $activity->record('tariff.created', __('Создан тариф: :tariff', ['tariff' => $tariff->name]), $request->user()->getKey(), $tariff, [
                'price' => (float) $tariff->price,
                'currency' => $tariff->currency,
                'duration_minutes' => $tariff->duration_minutes,
                'zone_count' => count($zoneIds),
            ]);
        });

        return redirect()->route('tariffs.index')->with('status', __('Тариф создан.'));
    }

    /** Changes apply to new purchases; time already sold and kept passes stay as they were. */
    public function update(UpdateTariffRequest $request, Tariff $tariff, ActivityLogger $activity): RedirectResponse
    {
        [$data, $zoneIds] = $this->attributes($request->validated());

        DB::transaction(function () use ($tariff, $data, $zoneIds, $request, $activity): void {
            $before = ['price' => (string) $tariff->price, 'duration_minutes' => $tariff->duration_minutes, 'is_active' => $tariff->is_active, 'zones' => $tariff->zones()->pluck('club_zones.id')->sort()->values()->all()];
            $tariff->update($data);
            $tariff->zones()->syncWithPivotValues($zoneIds, ['club_id' => $this->currentClub->id()]);
            $after = ['price' => (string) $tariff->price, 'duration_minutes' => $tariff->duration_minutes, 'is_active' => $tariff->is_active, 'zones' => collect($zoneIds)->map(fn ($id) => (int) $id)->sort()->values()->all()];
            $activity->record('tariff.updated', __('Изменён тариф: :tariff', ['tariff' => $tariff->name]), $request->user()->getKey(), $tariff, [
                'changed' => array_keys(array_filter($after, fn (mixed $value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH)),
                'price' => (float) $tariff->price,
                'duration_minutes' => $tariff->duration_minutes,
            ]);
        });

        return redirect()->route('tariffs.index')->with('status', __('Тариф сохранён.'));
    }

    private function page(?Tariff $editing): View
    {
        return view('tariffs.index', [
            'tariffs' => Tariff::query()->with('zones')->orderBy('duration_minutes')->orderBy('name')->get(),
            'zones' => ClubZone::query()->whereHas('computers')->orderBy('name')->get(),
            'currency' => data_get($this->currentClub->settings() ?: [], 'finances.currency', 'KZT'),
            'editing' => $editing,
            'editingValues' => $editing === null ? [] : $this->formValues($editing),
        ]);
    }

    /**
     * Form fields → model attributes: durations to minutes, limits off → null.
     *
     * @return array{0: array<string, mixed>, 1: list<int>} attributes and zone ids
     */
    private function attributes(array $data): array
    {
        $zoneIds = $data['zone_ids'] ?? [];
        $data['duration_minutes'] = $data['duration_value'] * self::DURATION_FACTORS[$data['duration_unit']];
        $data['usage_limit_minutes'] = $data['usage_limit_enabled']
            ? $data['usage_limit_value'] * self::DURATION_FACTORS[$data['usage_limit_unit']]
            : null;
        if (! $data['quantity_limit_enabled']) {
            $data['quantity_limit'] = null;
        }
        // The sale time is entered in the club's time; stored in UTC.
        foreach (['sale_starts_at', 'sale_ends_at'] as $field) {
            $data[$field] = empty($data[$field]) ? null : Carbon::createFromFormat('Y-m-d\\TH:i', $data[$field], $this->currentClub->timezone());
        }
        unset($data['zone_ids'], $data['duration_value'], $data['duration_unit'], $data['usage_limit_value'], $data['usage_limit_unit']);

        return [$data, $zoneIds];
    }

    /** @return array<string, mixed> the form fields of a tariff, as the form posts them */
    private function formValues(Tariff $tariff): array
    {
        [$duration, $durationUnit] = self::splitMinutes($tariff->duration_minutes);
        [$usage, $usageUnit] = self::splitMinutes((int) $tariff->usage_limit_minutes);

        return [
            'payment_method' => $tariff->payment_method,
            'name' => $tariff->name,
            'duration_value' => $duration,
            'duration_unit' => $durationUnit,
            'price' => (string) $tariff->price,
            'discount_enabled' => $tariff->discount_enabled ? '1' : '0',
            'color' => $tariff->color,
            'club_name' => $tariff->club_name,
            'zone_ids' => $tariff->zones->modelKeys(),
            'usage_limit_enabled' => $tariff->usage_limit_enabled ? '1' : '0',
            'usage_limit_value' => $tariff->usage_limit_enabled ? $usage : null,
            'usage_limit_unit' => $usageUnit,
            'quantity_limit_enabled' => $tariff->quantity_limit_enabled ? '1' : '0',
            'quantity_limit' => $tariff->quantity_limit,
            'sale_starts_at' => $tariff->sale_starts_at?->format('Y-m-d\\TH:i'),
            'sale_ends_at' => $tariff->sale_ends_at?->format('Y-m-d\\TH:i'),
            'validity_periods' => is_array($tariff->validity_periods) && $tariff->validity_periods !== [] ? $tariff->validity_periods : null,
            'is_active' => $tariff->is_active ? '1' : '0',
        ];
    }

    /** @return array{0: int, 1: string} minutes as the largest whole unit: days, hours or minutes */
    private static function splitMinutes(int $minutes): array
    {
        return match (true) {
            $minutes > 0 && $minutes % 1440 === 0 => [intdiv($minutes, 1440), 'days'],
            $minutes > 0 && $minutes % 60 === 0 => [intdiv($minutes, 60), 'hours'],
            default => [$minutes, 'minutes'],
        };
    }
}
