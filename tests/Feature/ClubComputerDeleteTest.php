<?php

use App\Models\ActivityLog;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\ComputerReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a manager deletes a PC from the club map, its bookings go with it', function () {
    $owner = User::factory()->create();
    $customer = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 7]);
    ComputerReservation::query()->create(['club_computer_id' => $computer->id, 'user_id' => $customer->id, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);

    $this->actingAs($owner)->get(route('club-map.index'))->assertOk()->assertSee('computer-delete-form', false);
    $this->actingAs($owner)->delete(route('club-computers.destroy', $computer))->assertRedirect()->assertSessionHas('status');

    expect(ClubComputer::query()->whereKey($computer->id)->exists())->toBeFalse()
        ->and(ComputerReservation::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'computer.deleted')->sole()->details['number'])->toBe(7);
});

test('a PC with a running session is not deleted, and the front desk cannot delete PCs', function () {
    User::factory()->create();
    $customer = User::factory()->create();
    $cashier = User::factory()->create();
    ClubMembership::query()->where('user_id', $cashier->id)->update(['role' => 'cashier']);
    $computer = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 1]);

    $this->actingAs($cashier)->delete(route('club-computers.destroy', $computer))->assertForbidden();

    $computer->forceFill(['current_session_id' => 's-1', 'current_session_user_id' => $customer->id, 'session_ends_at' => now()->addHour()])->save();
    $owner = User::query()->orderBy('id')->first();
    $this->actingAs($owner)->delete(route('club-computers.destroy', $computer))->assertSessionHasErrors('computer');

    expect(ClubComputer::query()->whereKey($computer->id)->exists())->toBeTrue();
});
