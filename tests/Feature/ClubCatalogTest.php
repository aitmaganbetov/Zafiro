<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

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
