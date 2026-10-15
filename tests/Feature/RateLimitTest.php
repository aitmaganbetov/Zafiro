<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Tariff;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the QR code polling of a PC does not use up the sign-in limit of the club IP', function () {
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['settings' => ['guests' => ['show_qr_code_on_login' => true]]])->save();
    $computer = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 1]);
    $token = app(ClubComputerSessions::class)->issueAgentToken($computer);
    app(CurrentClub::class)->clear();

    $id = $this->postJson(route('api.agent.qr-login.store'), [], ['X-Agent-Token' => $token])->assertCreated()->json('id');
    foreach (range(1, 25) as $_) {
        $this->postJson(route('api.agent.qr-login.poll'), ['id' => $id, 'device_name' => 'PC'], ['X-Agent-Token' => $token])->assertOk();
    }

    // Same IP: the password sign-in right after is checked, not refused with 429.
    $this->postJson(route('api.agent.user.login'), ['phone' => '+77011112233', 'password' => 'wrong', 'device_name' => 'PC'])->assertUnauthorized();
    $this->postJson(route('api.clubs.resolve'), ['club_key' => str_repeat('a', 64)])->assertStatus(401);
});

test('sign-in is limited per phone, so one customer cannot block the other PCs of the club', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson(route('api.agent.user.login'), ['phone' => '+77011112233', 'password' => 'wrong', 'device_name' => 'PC'])->assertUnauthorized();
    }

    $this->postJson(route('api.agent.user.login'), ['phone' => '+77011112233', 'password' => 'wrong', 'device_name' => 'PC'])->assertTooManyRequests();
    $this->postJson(route('api.agent.user.login'), ['phone' => '+77029998877', 'password' => 'wrong', 'device_name' => 'PC'])->assertUnauthorized();
});

test('a signed-in customer polling friends can still buy a tariff', function () {
    $user = User::factory()->create();
    $token = $user->createToken('PC', ['club:'.app(CurrentClub::class)->id()])->plainTextToken;
    $tariff = Tariff::query()->create(['name' => 'Час', 'duration_minutes' => 60, 'price' => 100, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true]);
    app(CurrentClub::class)->clear();
    $headers = ['Authorization' => 'Bearer '.$token];

    foreach (range(1, 15) as $_) {
        $this->getJson(route('api.guest.friends.index'), $headers)->assertOk();
    }

    $status = $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => 999], $headers)->status();
    expect($status)->not->toBe(429);
});
