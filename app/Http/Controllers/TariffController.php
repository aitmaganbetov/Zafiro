<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTariffRequest;
use App\Models\Tariff;
use App\Models\ClubZone;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class TariffController extends Controller
{
    public function index(): View
    {
        return view('tariffs.index', [
            'tariffs' => Tariff::query()->with('zones')->orderBy('duration_minutes')->orderBy('name')->get(),
            'zones' => ClubZone::query()->whereHas('computers')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreTariffRequest $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validated();
        $settings = Storage::exists('settings.json') ? json_decode(Storage::get('settings.json'), true) : [];
        $data['currency'] = data_get($settings ?: [], 'finances.currency', 'KZT');
        $zoneIds = $data['zone_ids'] ?? [];
        $durationFactors = ['minutes' => 1, 'hours' => 60, 'days' => 1440];
        $data['duration_minutes'] = $data['duration_value'] * $durationFactors[$data['duration_unit']];
        unset($data['zone_ids'], $data['duration_value'], $data['duration_unit']);

        if ($data['usage_limit_enabled']) {
            $data['usage_limit_minutes'] = $data['usage_limit_value'] * $durationFactors[$data['usage_limit_unit']];
        } else {
            $data['usage_limit_minutes'] = null;
        }
        unset($data['usage_limit_value'], $data['usage_limit_unit']);

        if (! $data['quantity_limit_enabled']) {
            $data['quantity_limit'] = null;
        }

        $tariff = Tariff::query()->create($data);
        $tariff->zones()->sync($zoneIds);
        $activity->record('tariff.created', "Создан тариф: {$tariff->name}", $request->user()?->getKey(), $tariff, [
            'price' => (float) $tariff->price,
            'currency' => $tariff->currency,
            'duration_minutes' => $tariff->duration_minutes,
            'zone_count' => count($zoneIds),
        ]);

        return redirect()->route('tariffs.index')->with('status', 'Тариф создан.');
    }
}