<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubPhoto;
use App\Models\ClubSubscription;
use App\Models\ClubZone;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** A club with a city and no subscription at all. */
function cityClub(string $name, ?string $city = 'aktobe', ?string $address = null): Club
{
    $club = Club::query()->create(['name' => $name, 'slug' => 'catalog-'.uniqid()]);
    $club->forceFill(['city' => $city, 'address' => $address])->save();

    return $club;
}

test('the club owner sets the city and address of the club', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('settings.club'))->assertOk()->assertSee('Город и адрес')->assertSee('Актобе');
    $this->post(route('settings.location'), ['city' => 'atlantis'])->assertSessionHasErrors('city');
    $this->post(route('settings.location'), ['city' => 'aktobe', 'address' => 'пр. Абилкайыр хана, 1'])->assertSessionHasNoErrors();

    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    expect($club->city)->toBe('aktobe')->and($club->address)->toBe('пр. Абилкайыр хана, 1')
        ->and(ActivityLog::query()->where('event', 'club.location_changed')->count())->toBe(1);

    $manager = User::factory()->create();
    ClubMembership::query()->where('user_id', $manager->id)->update(['role' => 'cashier']);
    $this->actingAs($manager)->post(route('settings.location'), ['city' => 'almaty'])->assertForbidden();
});

test('anyone sees the clubs of a city, with the cities and their coordinates', function () {
    cityClub('Бета', 'aktobe', 'ул. Маресьева, 5');
    cityClub('Альфа', 'aktobe');
    cityClub('Алматинский', 'almaty');
    cityClub('Без города', null);

    $response = $this->getJson(route('api.catalog.clubs', ['city' => 'aktobe']))->assertOk()
        ->assertJsonPath('city', 'aktobe')
        ->assertJsonCount(2, 'clubs')
        ->assertJsonPath('clubs.0.name', 'Альфа')
        ->assertJsonPath('clubs.1.address', 'ул. Маресьева, 5')
        ->assertJsonMissing(['name' => 'Без города']);

    $aktobe = collect($response->json('cities'))->firstWhere('key', 'aktobe');
    expect($aktobe)->toMatchArray(['name' => 'Актобе', 'clubs' => 2])->and($aktobe['lat'])->toBeFloat();

    $this->getJson(route('api.catalog.clubs'))->assertOk()->assertJsonPath('clubs', []);
    $this->getJson(route('api.catalog.clubs', ['city' => 'atlantis']))->assertUnprocessable();
});

test('a guest signs in to a club of the list, joins it and does not depend on its subscription', function () {
    $user = User::factory()->create(['phone' => '+77005550011', 'password' => Hash::make('secret-pass')]);
    $club = cityClub('Новый клуб');
    $hidden = cityClub('Без города', null);
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.auth.login'), ['phone' => '+77005550011', 'password' => 'secret-pass', 'club_id' => $hidden->id])
        ->assertForbidden();

    $token = $this->postJson(route('api.auth.login'), ['phone' => '+77005550011', 'password' => 'secret-pass', 'device_name' => 'mobileclient', 'club_id' => $club->id])
        ->assertOk()->assertJsonPath('club_id', $club->id)->assertJsonPath('user.balance', 0)
        ->json('token');

    expect(ClubMembership::query()->withoutGlobalScope('club')->where('club_id', $club->id)->where('user_id', $user->id)->value('role'))->toBe('client')
        ->and(MemberBalance::query()->withoutGlobalScope('club')->where('club_id', $club->id)->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(ActivityLog::query()->withoutGlobalScope('club')->where('club_id', $club->id)->where('event', 'user.joined_club')->exists())->toBeTrue()
        ->and(ClubSubscription::query()->where('club_id', $club->id)->exists())->toBeFalse();

    app('auth')->forgetGuards();
    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    $this->getJson(route('api.auth.me'), $headers)->assertOk();
    $this->getJson(route('api.guest.news'), $headers)->assertOk();
    $this->getJson(route('api.guest.history'), $headers)->assertOk();

    // Buying time still needs the club's subscription.
    $this->getJson(route('api.tariffs.index'), $headers)->assertStatus(402);

    // Signing in again does not join twice.
    $this->postJson(route('api.auth.login'), ['phone' => '+77005550011', 'password' => 'secret-pass', 'club_id' => $club->id])->assertOk();
    expect(ClubMembership::query()->withoutGlobalScope('club')->where('club_id', $club->id)->where('user_id', $user->id)->count())->toBe(1);
});

test('a signed-in guest moves to a club of the list and joins it; staff still need the subscription', function () {
    $user = User::factory()->create();
    $staff = User::factory()->create(['phone' => '+77005550022', 'password' => Hash::make('secret-pass')]);
    $club = cityClub('Соседний клуб');
    $token = $user->createToken('mobileclient', ['club:'.app(CurrentClub::class)->id()])->plainTextToken;
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.auth.switch-club'), ['club_id' => $club->id], ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('club_id', $club->id)->assertJsonFragment(['id' => $club->id, 'current' => true, 'role' => 'client']);

    // Staff of a club without a subscription cannot sign in to it.
    ClubMembership::query()->withoutGlobalScope('club')->forceCreate(['club_id' => $club->id, 'user_id' => $staff->id, 'role' => 'admin']);
    $this->postJson(route('api.auth.login'), ['phone' => '+77005550022', 'password' => 'secret-pass', 'club_id' => $club->id])
        ->assertStatus(402)->assertJsonPath('message', 'Подписка клуба не активна.');
});

/** A real PNG built without GD (not installed on the server). */
function clubPhotoPng(int $width = 320, int $height = 240): UploadedFile
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $rows = str_repeat("\0".str_repeat("\x20\x40\x80", $width), $height);

    return UploadedFile::fake()->createWithContent('photo.png', "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        .$chunk('IDAT', gzcompress($rows))
        .$chunk('IEND', ''));
}

test('the club fills its profile for the app: description, contacts, photos and zone equipment', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'VIP', 'price_per_hour' => 800]);
    ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1, 'cpu_model' => '11th Gen Intel(R) Core(TM) i5-11400F @ 2.60GHz', 'gpu_model' => 'NVIDIA GeForce RTX 3060', 'ram_total_mb' => 16253]);

    $this->actingAs($owner)->get(route('settings.profile'))->assertOk()
        ->assertSee('Зоны и оборудование')->assertSee('VIP')
        ->assertSee('placeholder="Intel Core i5-11400F"', false)->assertSee('placeholder="16 ГБ"', false);

    $this->post(route('settings.profile.update'), ['description' => 'Лучший клуб', 'phone' => '+7 700 000 00 00', 'hours' => 'Круглосуточно', 'instagram' => '@asia.game'])->assertSessionHasNoErrors();
    $this->post(route('settings.profile.update'), ['phone' => '<script>'])->assertSessionHasErrors('phone');
    expect(Club::query()->findOrFail(app(CurrentClub::class)->id())->profile)
        ->toBe(['description' => 'Лучший клуб', 'phone' => '+7 700 000 00 00', 'hours' => 'Круглосуточно', 'instagram' => 'asia.game']);

    $this->post(route('settings.photos.store'), ['photos' => [clubPhotoPng(), clubPhotoPng()]])->assertSessionHasNoErrors();
    $this->post(route('settings.photos.store'), ['photos' => [UploadedFile::fake()->createWithContent('x.png', 'not an image')]])->assertSessionHasErrors();
    [$first, $second] = ClubPhoto::query()->orderBy('sort_order')->get()->all();
    $this->post(route('settings.photos.cover', $second->id))->assertSessionHasNoErrors();
    expect(ClubPhoto::query()->orderBy('sort_order')->first()->id)->toBe($second->id);
    $this->delete(route('settings.photos.destroy', $first->id))->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($first->path);

    $this->put(route('settings.profile.zone', $zone->id), ['description' => 'Тихая комната', 'devices' => ['monitor' => 'ASUS 240 Гц', 'gpu' => '', 'mouse' => 'Logitech G102']])->assertSessionHasNoErrors();
    expect($zone->fresh()->devices)->toBe(['monitor' => 'ASUS 240 Гц', 'mouse' => 'Logitech G102'])->and($zone->fresh()->description)->toBe('Тихая комната');

    // The photo is public by its random name; another name is not found.
    $this->get($second->url())->assertOk();
    $this->get(route('club-photos.show', [$second->club_id, str_repeat('a', 40).'.png']))->assertNotFound();

    // Staff without the settings right cannot change it.
    $cashier = User::factory()->create();
    ClubMembership::query()->where('user_id', $cashier->id)->update(['role' => 'cashier']);
    $this->actingAs($cashier)->post(route('settings.profile.update'), ['description' => 'x'])->assertForbidden();
});

test('the app shows the club cards of a city and the club page with zones, free PCs and equipment', function () {
    Storage::fake('local');
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['city' => 'aktobe', 'address' => 'ул. Есет батыра, 138А', 'profile' => ['description' => 'Лучший клуб', 'hours' => 'Круглосуточно']])->save();
    $vip = ClubZone::query()->create(['name' => 'VIP', 'price_per_hour' => 800, 'devices' => ['monitor' => 'ASUS 240 Гц']]);
    $main = ClubZone::query()->create(['name' => 'Main', 'price_per_hour' => 500]);
    ClubComputer::query()->create(['club_zone_id' => $vip->id, 'number' => 1, 'last_seen_at' => now(), 'gpu_model' => 'NVIDIA GeForce RTX 3060']);
    ClubComputer::query()->create(['club_zone_id' => $vip->id, 'number' => 2, 'last_seen_at' => now(), 'is_reserved' => true]);
    ClubComputer::query()->create(['club_zone_id' => $main->id, 'number' => 3, 'last_seen_at' => now()->subHour()]);
    $photo = ClubPhoto::query()->create(['path' => 'club-photos/'.$club->id.'/'.str_repeat('b', 40).'.jpg', 'sort_order' => 1]);
    app(CurrentClub::class)->clear();

    $this->getJson(route('api.catalog.clubs', ['city' => 'aktobe']))->assertOk()
        ->assertJsonPath('clubs.0.pcs', 3)
        ->assertJsonPath('clubs.0.free_pcs', 1)
        ->assertJsonPath('clubs.0.price_from', 500)
        ->assertJsonPath('clubs.0.hours', 'Круглосуточно')
        ->assertJsonPath('clubs.0.photo_url', $photo->url());

    $this->getJson(route('api.catalog.club', $club->id), ['Accept-Language' => 'ru'])->assertOk()
        ->assertJsonPath('city', 'Актобе')
        ->assertJsonPath('description', 'Лучший клуб')
        ->assertJsonPath('photos', [$photo->url()])
        ->assertJsonPath('zones.0.name', 'VIP')
        ->assertJsonPath('zones.0.pcs', 2)
        ->assertJsonPath('zones.0.free_pcs', 1)
        ->assertJsonPath('zones.0.devices', [
            ['key' => 'gpu', 'label' => 'Видеокарта', 'value' => 'NVIDIA GeForce RTX 3060'],
            ['key' => 'monitor', 'label' => 'Монитор', 'value' => 'ASUS 240 Гц'],
        ])
        ->assertJsonPath('zones.1.free_pcs', 0);

    $hidden = cityClub('Без города', null);
    $this->getJson(route('api.catalog.club', $hidden->id))->assertNotFound();
});
