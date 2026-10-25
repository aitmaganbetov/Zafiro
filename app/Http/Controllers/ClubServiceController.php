<?php

namespace App\Http\Controllers;

use App\Models\ClubService;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Support\ClubPermissions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The club's services (PlayStation, printing…): sold at the cash register like goods, without stock. */
class ClubServiceController extends Controller
{
    public function index(CurrentClub $currentClub): View
    {
        $membership = $currentClub->membership();

        return view('services.index', [
            'services' => ClubService::query()->orderByDesc('is_active')->orderBy('name')->paginate(50),
            'canCreate' => ClubPermissions::allows($membership, 'services.create'),
            'canUpdate' => ClubPermissions::allows($membership, 'services.update'),
            'canDelete' => ClubPermissions::allows($membership, 'services.delete'),
        ]);
    }

    public function store(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $service = ClubService::query()->create($this->validated($request));
        $activity->record('service.created', __('Создана услуга: :service', ['service' => $service->name]), $request->user()->getKey(), $service, [
            'price' => (float) $service->price,
            'unit' => $service->unit,
        ]);

        return redirect()->route('services.index')->with('status', __('Услуга «:service» добавлена.', ['service' => $service->name]));
    }

    public function update(Request $request, ClubService $service, ActivityLogger $activity): RedirectResponse
    {
        $service->update($this->validated($request));
        $activity->record('service.updated', __('Изменена услуга: :service', ['service' => $service->name]), $request->user()->getKey(), $service, [
            'price' => (float) $service->price,
            'unit' => $service->unit,
            'is_active' => $service->is_active,
        ]);

        return redirect()->route('services.index')->with('status', __('Услуга «:service» сохранена.', ['service' => $service->name]));
    }

    /** Receipts keep the name and price of what was sold: deleting a service changes no history. */
    public function destroy(Request $request, ClubService $service, ActivityLogger $activity): RedirectResponse
    {
        $service->delete();
        $activity->record('service.deleted', __('Удалена услуга: :service', ['service' => $service->name]), $request->user()->getKey(), $service);

        return redirect()->route('services.index')->with('status', __('Услуга «:service» удалена.', ['service' => $service->name]));
    }

    /** @return array{name: string, price: string, unit: ?string, is_active: bool} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'unit' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return ['name' => trim($data['name']), 'price' => $data['price'], 'unit' => filled($data['unit'] ?? null) ? trim($data['unit']) : null, 'is_active' => $request->boolean('is_active', true)];
    }
}
