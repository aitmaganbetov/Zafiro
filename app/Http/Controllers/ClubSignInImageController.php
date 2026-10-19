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
 * The club's picture on the sign-in screen of the PC agent (instead of the standard one). Public
 * by its random file name like the logo: the agent downloads it without a token.
 */
class ClubSignInImageController extends Controller
{
    public function __construct(private CurrentClub $currentClub) {}

    public function show(Club $club, string $file): StreamedResponse
    {
        abort_unless($club->signin_image_path !== null && basename($club->signin_image_path) === $file, 404);
        abort_unless(Storage::disk('local')->exists($club->signin_image_path), 404);

        return Storage::disk('local')->response($club->signin_image_path, $file, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function update(Request $request, ActivityLogger $activity): RedirectResponse
    {
        // PNG and JPEG only (Windows decodes them without extra codecs); the agent fills the left part of the screen.
        $data = $request->validate([
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:3072', 'dimensions:min_width=640,min_height=360,max_width=4096,max_height=4096'],
        ], [
            'image.dimensions' => __('Картинка — от 640×360 до 4096×4096 пикселей.'),
            'image.max' => __('Картинка — не больше 3 МБ.'),
        ]);
        $club = $this->currentClub->get();
        $extension = $data['image']->guessExtension() === 'png' ? 'png' : 'jpg';
        $path = $data['image']->storeAs('club-signin-images/'.$club->getKey(), Str::random(40).'.'.$extension, 'local');
        $previous = $club->signin_image_path;

        DB::transaction(function () use ($club, $path, $request, $activity): void {
            $club->forceFill(['signin_image_path' => $path])->save();
            $activity->record('club.signin_image_changed', __('Картинка экрана входа изменена'), $request->user()->getKey(), $club);
        });
        $this->deleteFile($previous);

        return back()->with('status', __('Картинка экрана входа сохранена.'));
    }

    public function destroy(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $club = $this->currentClub->get();
        $previous = $club->signin_image_path;

        if ($previous !== null) {
            DB::transaction(function () use ($club, $request, $activity): void {
                $club->forceFill(['signin_image_path' => null])->save();
                $activity->record('club.signin_image_changed', __('Картинка экрана входа удалена'), $request->user()->getKey(), $club);
            });
            $this->deleteFile($previous);
        }

        return back()->with('status', __('Картинка экрана входа удалена: в агенте снова стандартная.'));
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }
    }
}
