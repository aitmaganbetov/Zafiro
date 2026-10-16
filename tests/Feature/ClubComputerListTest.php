<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('club PC list displays all computer details and hides other clubs', function () {
    $user = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'VIP']);
    ClubComputer::query()->create([
        'club_zone_id' => $zone->id,
        'number' => 7,
        'device_name' => 'PC-07',
        'device_id' => 'smbios-07',
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'local_ip' => '192.168.1.7',
        'remote_ip' => '203.0.113.7',
        'cpu_model' => 'AMD Ryzen 7 5800X',
        'cpu_cores' => 16,
        'ram_total_mb' => 65536,
        'gpu_model' => 'NVIDIA GeForce RTX 4070',
        'os_version' => 'Windows 11 Pro',
        'agent_state' => 'idle',
        'agent_version' => '1.4.0',
        'current_session_id' => 'session-07',
        'last_seen_at' => now(),
        'hardware_reported_at' => now(),
    ]);

    $otherClub = Club::query()->create(['name' => 'Other club', 'slug' => 'pc-list-other-club']);
    app(CurrentClub::class)->set($otherClub);
    $otherZone = ClubZone::query()->create(['name' => 'Other zone']);
    ClubComputer::query()->create([
        'club_zone_id' => $otherZone->id,
        'number' => 1,
        'device_name' => 'SECRET-PC',
    ]);
    app(CurrentClub::class)->clear();

    $this->actingAs($user)->get(route('club-computers.index'))
        ->assertSuccessful()
        ->assertSee('Список ПК')
        ->assertSee('PC-07')
        ->assertSee('VIP')
        ->assertSee('AA:BB:CC:DD:EE:FF')
        ->assertSee('192.168.1.7')
        ->assertSee('203.0.113.7')
        ->assertSee('AMD Ryzen 7 5800X')
        ->assertSee('64,0 GB')
        ->assertSee('NVIDIA GeForce RTX 4070')
        ->assertSee('Windows 11 Pro')
        ->assertSee('smbios-07')
        ->assertSee('session-07')
        ->assertSee('1.4.0')
        ->assertSee('Online')
        ->assertDontSee('SECRET-PC');
});

test('operators and cashiers can view the PC inventory', function () {
    $operator = User::factory()->create();
    $operator->currentMembership()->firstOrFail()->update(['role' => 'operator']);

    $this->actingAs($operator)->get(route('club-computers.index'))->assertSuccessful();

    $cashier = User::factory()->create();
    $cashier->currentMembership()->firstOrFail()->update(['role' => 'cashier']);

    $this->actingAs($cashier)->get(route('club-computers.index'))->assertSuccessful();
});
