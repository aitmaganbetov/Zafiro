<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubPhoto;
use App\Models\ClubZone;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Support\ZoneDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What guests see about the club in the mobile app: description and contacts, photos and the
 * equipment of each zone (settings → club profile).
 */
class ClubProfileController extends Controller
{
    public function __construct(private CurrentClub $currentClub) {}

    public function show(): View
    {
        $club = $this->currentClub->get();
        $zones = ClubZone::query()->withCount('computers')->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'description', 'price_per_hour', 'devices']);

        return view('settings.profile', [
            'profile' => $club->profile ?? [],
            'photos' => ClubPhoto::query()->orderBy('sort_order')->orderBy('id')->get(),
            'zones' => $zones,
            'detected' => ZoneDevices::detected($club->getKey(), $zones->modelKeys()),
            'deviceFields' => ZoneDevices::fields(),
        ]);
    }

    public function update(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+\d\s()\-]+$/'],
            'hours' => ['nullable', 'string', 'max:100'],
            'instagram' => ['nullable', 'string', 'max:64', 'regex:/^@?[A-Za-z0-9._]+$/'],
        ], ['phone.regex' => __('Телефон — только цифры, пробелы, скобки, «+» и «-».'), 'instagram.regex' => __('Instagram — имя аккаунта, например @club.')]);
        $club = $this->currentClub->get();
        $profile = array_filter(array_map(fn ($value) => is_string($value) ? trim($value) : null, $data), fn ($value) => $value !== null && $value !== '');
        if (isset($profile['instagram'])) {
            $profile['instagram'] = ltrim($profile['instagram'], '@');
        }

        DB::transaction(function () use ($club, $profile, $request, $activity): void {
            $club->forceFill(['profile' => $profile ?: null])->save();
            $activity->record('club.profile_changed', __('Описание клуба для приложения изменено'), $request->user()->getKey(), $club);
        });

        return back()->with('status', __('Описание клуба сохранено.'));
    }

    public function storePhotos(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $request->validate([
            'photos' => ['required', 'array', 'max:'.ClubPhoto::LIMIT],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:3072', 'dimensions:min_width=200,min_height=200,max_width=6000,max_height=6000'],
        ], ['photos.*.max' => __('Фото — не больше 3 МБ.')]);
        $club = $this->currentClub->get();
        $files = $request->file('photos');

        if (ClubPhoto::query()->count() + count($files) > ClubPhoto::LIMIT) {
            throw ValidationException::withMessages(['photos' => __('У клуба может быть не больше :count фото.', ['count' => ClubPhoto::LIMIT])]);
        }

        $order = (int) ClubPhoto::query()->max('sort_order');
        $paths = [];
        foreach ($files as $file) {
            $extension = match ($file->guessExtension()) {
                'png' => 'png',
                'webp' => 'webp',
                default => 'jpg',
            };
            $paths[] = $file->storeAs('club-photos/'.$club->getKey(), Str::random(40).'.'.$extension, 'local');
        }

        DB::transaction(function () use ($paths, $order, $club, $request, $activity): void {
            foreach ($paths as $path) {
                ClubPhoto::query()->create(['path' => $path, 'sort_order' => ++$order]);
            }
            $activity->record('club.photos_added', trans_choice('Добавлено :count фото клуба|Добавлено :count фото клуба|Добавлено :count фото клуба', count($paths)), $request->user()->getKey(), $club);
        });

        return back()->with('status', __('Фото добавлены.'));
    }

    public function destroyPhoto(Request $request, int $photo, ActivityLogger $activity): RedirectResponse
    {
        $photo = ClubPhoto::query()->findOrFail($photo);
        $club = $this->currentClub->get();

        DB::transaction(function () use ($photo, $club, $request, $activity): void {
            $photo->delete();
            $activity->record('club.photo_deleted', __('Фото клуба удалено'), $request->user()->getKey(), $club);
        });
        Storage::disk('local')->delete($photo->path);

        return back()->with('status', __('Фото удалено.'));
    }

    /** Make the photo the first one: it is the cover of the club in the app. */
    public function coverPhoto(int $photo): RedirectResponse
    {
        $photo = ClubPhoto::query()->findOrFail($photo);
        $photo->update(['sort_order' => (int) ClubPhoto::query()->min('sort_order') - 1]);

        return back()->with('status', __('Обложка клуба изменена.'));
    }

    public function updateZone(Request $request, int $zone, ActivityLogger $activity): RedirectResponse
    {
        $zone = ClubZone::query()->findOrFail($zone);
        $rules = ['description' => ['nullable', 'string', 'max:500']];
        foreach (array_keys(ZoneDevices::fields()) as $key) {
            $rules['devices.'.$key] = ['nullable', 'string', 'max:120'];
        }
        $data = $request->validate($rules);
        $devices = array_filter(array_map(fn ($value) => trim((string) $value), $data['devices'] ?? []), fn (string $value) => $value !== '');

        DB::transaction(function () use ($zone, $data, $devices, $request, $activity): void {
            $zone->forceFill(['description' => $data['description'] ?? null, 'devices' => $devices ?: null])->save();
            $activity->record('club.zone_devices_changed', __('Оборудование зоны :zone изменено', ['zone' => $zone->name]), $request->user()->getKey(), $zone);
        });

        return redirect()->to(route('settings.profile').'#zone-'.$zone->getKey())->with('status', __('Зона :zone сохранена.', ['zone' => $zone->name]));
    }

    /** The photo for the app: public by its random file name, like the club logo. */
    public function photo(Club $club, string $file): StreamedResponse
    {
        $path = 'club-photos/'.$club->getKey().'/'.$file;
        abort_unless(ClubPhoto::query()->withoutGlobalScope('club')->where('club_id', $club->getKey())->where('path', $path)->exists(), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $file, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
