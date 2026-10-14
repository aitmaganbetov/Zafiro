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

function currentLogoClub(): Club
{
    return Club::query()->findOrFail(app(CurrentClub::class)->id());
}

/** A real PNG built without GD (not installed on the server). */
function logoPng(int $width = 64, int $height = 64, string $name = 'logo.png'): UploadedFile
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $rows = str_repeat("\0".str_repeat("\xff\x00\x00", $width), $height);
    $png = "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        .$chunk('IDAT', gzcompress($rows))
        .$chunk('IEND', '');

    return UploadedFile::fake()->createWithContent($name, $png);
}

test('an owner uploads the club logo; it is served publicly by its random name and logged', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('settings.club'))->assertOk()
        ->assertSee(__('Логотип не загружен: на мониторах показывается только название клуба.'));
    $this->actingAs($owner)->post(route('settings.logo'), ['logo' => logoPng(256, 128)])
        ->assertRedirect()->assertSessionHas('status', __('Логотип сохранён.'));

    $club = currentLogoClub();
    $url = $club->logoUrl();
    expect($club->logo_path)->toMatch('#^club-logos/'.$club->id.'/[A-Za-z0-9]{40}\.png$#')
        ->and($url)->toBe(route('club-logos.show', [$club, basename($club->logo_path)]))
        ->and(ActivityLog::query()->where('event', 'club.logo_changed')->exists())->toBeTrue();
    Storage::disk('local')->assertExists($club->logo_path);

    $this->actingAs($owner)->get(route('settings.club'))->assertOk()->assertSee($url, false);

    auth()->logout();
    $this->get($url)->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'image/png');
    $this->get(route('club-logos.show', [$club, str_repeat('a', 40).'.png']))->assertNotFound();
});

test('a new upload replaces the old file and removing the logo deletes it', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->post(route('settings.logo'), ['logo' => logoPng()]);
    $first = currentLogoClub()->logo_path;
    $this->actingAs($owner)->post(route('settings.logo'), ['logo' => logoPng(128, 128)]);
    $second = currentLogoClub()->logo_path;

    expect($second)->not->toBe($first)->toEndWith('.png');
    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($second);

    $this->actingAs($owner)->delete(route('settings.logo.destroy'))->assertRedirect();

    expect(currentLogoClub()->logo_path)->toBeNull();
    Storage::disk('local')->assertMissing($second);
});

test('only small PNG or JPEG images are accepted', function (UploadedFile $file) {
    $owner = User::factory()->create();

    $this->actingAs($owner)->post(route('settings.logo'), ['logo' => $file])->assertSessionHasErrors('logo');

    expect(currentLogoClub()->logo_path)->toBeNull();
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'),
    'webp' => fn () => UploadedFile::fake()->createWithContent('logo.webp', 'RIFF'.pack('V', 30).'WEBPVP8 '.str_repeat("\0", 22)),
    'too large' => fn () => logoPng()->size(2048),
    'too small' => fn () => logoPng(8, 8),
    'not an image' => fn () => UploadedFile::fake()->create('logo.png', 10, 'application/pdf'),
]);

test('cashiers and operators cannot change the logo', function (string $role) {
    $user = User::factory()->create();
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role]);

    $this->actingAs($user)->post(route('settings.logo'), ['logo' => logoPng()])->assertForbidden();

    expect(currentLogoClub()->logo_path)->toBeNull();
})->with(['cashier', 'operator']);

test('the agent heartbeat reports the club name and logo URL', function () {
    $owner = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);
    $token = app(ClubComputerSessions::class)->issueAgentToken($computer);
    $club = currentLogoClub();
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('club.name', $club->name)
        ->assertJsonPath('club.logo_url', null);

    $this->actingAs($owner)->post(route('settings.logo'), ['logo' => logoPng()]);
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('club.logo_url', $club->refresh()->logoUrl());
});
