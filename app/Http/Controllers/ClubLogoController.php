<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Club logo for the locked PC monitors. It is public by its random file name:
 * the agent downloads it without a token, and a new upload gets a new URL.
 */
class ClubLogoController extends Controller
{
    public function __construct(private CurrentClub $currentClub) {}

    public function show(Club $club, string $file): StreamedResponse
    {
        abort_unless($club->logo_path !== null && basename($club->logo_path) === $file, 404);
        abort_unless(Storage::disk('local')->exists($club->logo_path), 404);

        return Storage::disk('local')->response($club->logo_path, $file, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function update(Request $request, ActivityLogger $activity): RedirectResponse
    {
        // PNG and JPEG only: Windows decodes them without extra codecs.
        $data = $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:min_width=32,min_height=32,max_width=4096,max_height=4096'],
        ]);
        $club = $this->currentClub->get();
        $extension = $data['logo']->guessExtension() === 'png' ? 'png' : 'jpg';
        $path = $data['logo']->storeAs('club-logos/'.$club->getKey(), Str::random(40).'.'.$extension, 'local');
        $previous = $club->logo_path;

        DB::transaction(function () use ($club, $path, $request, $activity): void {
            $club->forceFill(['logo_path' => $path])->save();
            $activity->record('club.logo_changed', __('Логотип клуба изменён'), $request->user()->getKey(), $club);
        });
        $this->deleteFile($previous);

        return back()->with('status', __('Логотип сохранён.'));
    }

    public function destroy(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $club = $this->currentClub->get();
        $previous = $club->logo_path;

        if ($previous !== null) {
            DB::transaction(function () use ($club, $request, $activity): void {
                $club->forceFill(['logo_path' => null])->save();
                $activity->record('club.logo_changed', __('Логотип клуба удалён'), $request->user()->getKey(), $club);
            });
            $this->deleteFile($previous);
        }

        return back()->with('status', __('Логотип удалён.'));
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }
    }
}
