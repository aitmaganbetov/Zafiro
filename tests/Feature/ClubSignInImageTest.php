<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

/** A real PNG built without GD (not installed on the server). */
function signInPng(int $width, int $height): UploadedFile
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $rows = str_repeat("\0".str_repeat("\x10\x20\x30", $width), $height);

    return UploadedFile::fake()->createWithContent('room.png', "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        .$chunk('IDAT', gzcompress($rows))
        .$chunk('IEND', ''));
}

test('the club sets its own sign-in screen picture; the agent gets its public URL; it can go back to the standard one', function () {
    $owner = User::factory()->create();
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $computer = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 1]);
    $token = app(ClubComputerSessions::class)->issueAgentToken($computer);
    $heartbeat = function () use ($token) {
        app(CurrentClub::class)->clear();

        return $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk();
    };

    $this->actingAs($owner)->get(route('settings.club'))->assertOk()->assertSee(__('Стандартная картинка Zafiro'))->assertSee('href="#signin-image"', false);
    $heartbeat()->assertJsonPath('club.signin_image_url', null);

    $this->actingAs($owner)->post(route('settings.signin-image'), ['image' => signInPng(320, 200)])->assertSessionHasErrors('image');
    $this->post(route('settings.signin-image'), ['image' => signInPng(1280, 720)])->assertSessionHasNoErrors();

    $path = $club->refresh()->signin_image_path;
    expect($path)->toMatch('#^club-signin-images/'.$club->id.'/[A-Za-z0-9]{40}\.png$#')
        ->and(ActivityLog::query()->where('event', 'club.signin_image_changed')->exists())->toBeTrue();
    $url = $club->signInImageUrl();
    $heartbeat()->assertJsonPath('club.signin_image_url', $url);

    // Public by its name, nothing else.
    auth()->logout();
    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    $this->get(route('club-signin-images.show', [$club, str_repeat('a', 40).'.png']))->assertNotFound();

    $this->actingAs($owner)->delete(route('settings.signin-image.destroy'))->assertSessionHasNoErrors();
    expect($club->refresh()->signin_image_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
    $heartbeat()->assertJsonPath('club.signin_image_url', null);
});

test('only managers change the sign-in picture', function () {
    User::factory()->create();
    $cashier = User::factory()->create();
    ClubMembership::query()->where('user_id', $cashier->id)->update(['role' => 'cashier']);

    $this->actingAs($cashier)->post(route('settings.signin-image'), ['image' => signInPng(1280, 720)])->assertForbidden();
});
