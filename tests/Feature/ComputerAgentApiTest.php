<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubSubscription;
use App\Models\ClubZone;
use App\Models\SaasPlan;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('agent resolves a configured computer case-insensitively and binds its stable id', function () {
    $user = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'VIP']);
    $computer = ClubComputer::query()->create([
        'club_zone_id' => $zone->id,
        'number' => 7,
        'device_name' => 'PC-07',
        'device_id' => 'stable-machine-id',
    ]);
    $token = $user->createToken('agent', ['club:'.$computer->club_id])->plainTextToken;
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.club-computers.resolve'), ['device_name' => 'PC-07'])
        ->assertUnauthorized();

    $this->withToken($token)->postJson(route('api.club-computers.resolve'), [
        'device_name' => 'different-name',
        'device_id' => 'stable-machine-id',
    ])->assertSuccessful()
        ->assertJsonPath('computer_id', $computer->id)
        ->assertJsonPath('number', '7')
        ->assertJsonPath('name', 'PC-07')
        ->assertJsonPath('zone.id', $zone->id)
        ->assertJsonPath('zone.name', 'VIP');

    $this->withToken($token)->postJson(route('api.club-computers.resolve'), [
        'device_name' => 'pc-07',
    ])->assertSuccessful()->assertJsonPath('computer_id', $computer->id);

    $this->withToken($token)->postJson(route('api.club-computers.resolve'), [
        'device_name' => 'unknown-pc',
    ])->assertNotFound()
        ->assertJsonPath('code', 'computer_not_found');
});

test('computer resolution requires a token and an active club subscription', function () {
    $this->postJson(route('api.club-computers.resolve'), ['device_name' => 'PC-07'])
        ->assertUnauthorized();

    $user = User::factory()->create();
    $clubId = app(CurrentClub::class)->id();
    ClubSubscription::query()->where('club_id', $clubId)->update([
        'status' => 'trialing',
        'trial_ends_at' => now()->subSecond(),
    ]);
    $token = $user->createToken('agent', ['club:'.$clubId])->plainTextToken;
    app(CurrentClub::class)->clear();

    $this->withToken($token)->postJson(route('api.club-computers.resolve'), [
        'device_name' => 'PC-07',
    ])->assertStatus(402);
});

test('empty heartbeat remains compatible and full heartbeat stores agent state', function () {
    $user = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Основной зал']);
    $computer = ClubComputer::query()->create([
        'club_zone_id' => $zone->id,
        'number' => 7,
        'device_name' => 'PC-07',
    ]);
    $token = $user->createToken('agent', ['club:'.$computer->club_id])->plainTextToken;
    app(CurrentClub::class)->clear();
    $frozenTime = Carbon::parse('2026-10-13 12:00:00', 'UTC');
    Carbon::setTestNow($frozenTime);

    try {
        $this->withToken($token)->postJson(route('api.club-computers.heartbeat', $computer))
            ->assertSuccessful()
            ->assertJsonPath('computer_id', $computer->id)
            ->assertJsonPath('status', 'online')
            ->assertJsonPath('recommended_interval_seconds', 30)
            ->assertJsonPath('heartbeat_interval_seconds', 30)
            ->assertJsonPath('number', '7')
            ->assertJsonPath('zone.name', 'Основной зал');

        $this->withToken($token)->postJson(route('api.club-computers.heartbeat', $computer), [
            'state' => 'in_session',
            'session_id' => 'session-42',
            'agent_version' => '1.4.0',
            'device_name' => 'PC-07',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'local_ip' => '192.168.1.7',
            'cpu_model' => 'AMD Ryzen 7 5800X',
            'cpu_cores' => 16,
            'ram_total_mb' => 65536,
            'gpu_model' => 'NVIDIA GeForce RTX 4070',
            'os_version' => 'Windows 11 Pro',
        ])->assertSuccessful()
            ->assertJsonPath('status', 'online')
            ->assertJsonPath('hardware.mac_address', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonPath('hardware.local_ip', '192.168.1.7')
            ->assertJsonPath('hardware.cpu_cores', 16)
            ->assertJsonPath('hardware.ram_total_mb', 65536)
            ->assertJsonPath('hardware.gpu_model', 'NVIDIA GeForce RTX 4070');

        $computer->refresh();
        expect($computer->agent_state)->toBe('in_session')
            ->and($computer->current_session_id)->toBe('session-42')
            ->and($computer->agent_version)->toBe('1.4.0')
            ->and($computer->mac_address)->toBe('AA:BB:CC:DD:EE:FF')
            ->and($computer->cpu_model)->toBe('AMD Ryzen 7 5800X')
            ->and($computer->gpu_model)->toBe('NVIDIA GeForce RTX 4070')
            ->and($computer->os_version)->toBe('Windows 11 Pro')
            ->and($computer->remote_ip)->not->toBeNull()
            ->and($computer->hardware_reported_at)->not->toBeNull()
            ->and($computer->last_seen_at->toIso8601String())->toBe($frozenTime->toIso8601String());

        Carbon::setTestNow($frozenTime->copy()->addSeconds(91));
        expect($computer->fresh()->connectionStatus())->toBe('offline');
    } finally {
        Carbon::setTestNow();
    }
});

test('heartbeat rejects unsupported states and session ids outside a session', function () {
    $user = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Основной зал']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);
    $token = $user->createToken('agent', ['club:'.$computer->club_id])->plainTextToken;
    app(CurrentClub::class)->clear();

    $this->withToken($token)->postJson(route('api.club-computers.heartbeat', $computer), [
        'state' => 'unknown',
    ])->assertUnprocessable()->assertJsonValidationErrors('state');

    $this->withToken($token)->postJson(route('api.club-computers.heartbeat', $computer), [
        'state' => 'idle',
        'session_id' => 'session-42',
    ])->assertUnprocessable()->assertJsonValidationErrors('session_id');

    $this->withToken($token)->postJson(route('api.club-computers.heartbeat', $computer), [
        'mac_address' => 'not-a-mac',
        'local_ip' => 'invalid-ip',
    ])->assertUnprocessable()->assertJsonValidationErrors(['mac_address', 'local_ip']);
});

test('heartbeat forbids access to a computer in another club', function () {
    $user = User::factory()->create();
    $token = $user->createToken('agent', ['club:'.$user->currentMembership()->value('club_id')])->plainTextToken;
    $otherClub = Club::query()->create(['name' => 'Чужой клуб', 'slug' => 'other-computer-club']);
    app(CurrentClub::class)->set($otherClub);
    $otherZone = ClubZone::query()->create(['name' => 'Другой зал']);
    $otherComputer = ClubComputer::query()->create([
        'club_zone_id' => $otherZone->id,
        'number' => 1,
        'device_name' => 'Other-01',
        'device_id' => 'foreign-device-id',
    ]);
    app(CurrentClub::class)->clear();

    $this->withToken($token)->postJson(route('api.club-computers.resolve'), [
        'device_name' => 'Other-01',
        'device_id' => 'foreign-device-id',
    ])->assertNotFound()->assertJsonPath('code', 'computer_not_found');

    $this->withToken($token)
        ->postJson(route('api.club-computers.heartbeat', $otherComputer), [])
        ->assertForbidden();
});

test('agent registration creates a computer and repeated requests do not create duplicates', function () {
    $user = User::factory()->create();
    $clubId = $user->currentMembership()->value('club_id');
    $token = $user->createToken('agent', ['club:'.$clubId])->plainTextToken;
    app(CurrentClub::class)->clear();

    $payload = [
        'device_name' => 'PC-07',
        'device_id' => 'smbios-pc-07',
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
    ];

    $created = $this->withToken($token)->postJson(route('api.club-computers.register'), $payload)
        ->assertCreated()
        ->assertJsonPath('name', 'PC-07')
        ->assertJsonPath('number', '1')
        ->assertJsonPath('zone.name', 'Не назначена');

    $computerId = $created->json('computer_id');
    $this->assertDatabaseHas('club_computers', [
        'id' => $computerId,
        'club_id' => $clubId,
        'device_id' => 'smbios-pc-07',
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
    ]);

    $this->withToken($token)->postJson(route('api.club-computers.register'), [
        ...$payload,
        'device_name' => 'Changed host name',
    ])->assertSuccessful()->assertJsonPath('computer_id', $computerId);

    $this->withToken($token)->postJson(route('api.club-computers.register'), [
        'device_name' => 'pc-07',
    ])->assertSuccessful()->assertJsonPath('computer_id', $computerId);

    expect(ClubComputer::query()->count())->toBe(1)
        ->and(ClubZone::query()->where('name', 'Не назначена')->count())->toBe(1);
});

test('registration requires auth and computer management rights', function () {
    $this->postJson(route('api.club-computers.register'), ['device_name' => 'PC-01'])
        ->assertUnauthorized();

    $user = User::factory()->create();
    $user->currentMembership()->firstOrFail()->update(['role' => 'cashier']);
    $token = $user->createToken('cashier', ['club:'.$user->currentMembership()->value('club_id')])->plainTextToken;
    app(CurrentClub::class)->clear();

    $this->withToken($token)->postJson(route('api.club-computers.register'), [
        'device_name' => 'PC-01',
    ])->assertForbidden()
        ->assertJsonPath('message', 'Недостаточно прав для регистрации компьютера в клубе.');

    expect(ClubComputer::query()->count())->toBe(0);
});

test('registration validates club zone and zone capacity', function () {
    $user = User::factory()->create();
    $clubId = $user->currentMembership()->value('club_id');
    $token = $user->createToken('agent', ['club:'.$clubId])->plainTextToken;
    $fullZone = ClubZone::query()->create(['name' => 'Full', 'capacity' => 0]);
    $otherClub = Club::query()->create(['name' => 'Another', 'slug' => 'registration-other-club']);
    app(CurrentClub::class)->set($otherClub);
    $foreignZone = ClubZone::query()->create(['name' => 'Foreign']);
    app(CurrentClub::class)->clear();

    $this->withToken($token)->postJson(route('api.club-computers.register'), [
        'device_name' => 'PC-FULL',
        'zone_id' => $fullZone->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('zone_id');

    $this->withToken($token)->postJson(route('api.club-computers.register'), [
        'device_name' => 'PC-FOREIGN',
        'zone_id' => $foreignZone->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('zone_id');

    expect(ClubComputer::query()->count())->toBe(0);
});

test('registration respects inactive subscriptions and plan computer limits', function () {
    $user = User::factory()->create();
    $clubId = $user->currentMembership()->value('club_id');
    $token = $user->createToken('agent', ['club:'.$clubId])->plainTextToken;
    $subscription = ClubSubscription::query()->where('club_id', $clubId)->firstOrFail();
    app(CurrentClub::class)->clear();

    $subscription->update(['status' => 'trialing', 'trial_ends_at' => now()->subSecond()]);
    $this->withToken($token)->postJson(route('api.club-computers.register'), [
        'device_name' => 'PC-EXPIRED',
    ])->assertStatus(402);

    $subscription->update(['status' => 'active', 'trial_ends_at' => null]);
    $subscription->plan->update(['features' => ['max_computers' => 0]]);
    $this->withToken($token)->postJson(route('api.club-computers.register'), [
        'device_name' => 'PC-LIMIT',
    ])->assertUnprocessable()->assertJsonValidationErrors('device_name');

    expect(ClubComputer::query()->count())->toBe(0);
});

test('the same stable device id can be registered independently in separate clubs', function () {
    $currentClub = app(CurrentClub::class);
    $firstClub = $currentClub->get();
    $firstUser = User::factory()->create();
    $firstToken = $firstUser->createToken('agent-a', ['club:'.$firstClub->id])->plainTextToken;

    $firstResponse = $this->withToken($firstToken)->postJson(route('api.club-computers.register'), [
        'device_name' => 'PC-07',
        'device_id' => 'shared-smbios-id',
    ])->assertCreated();

    $secondClub = Club::query()->create(['name' => 'Second club', 'slug' => 'second-device-id-club']);
    $currentClub->set($secondClub);
    $secondUser = User::factory()->create();
    $plan = SaasPlan::query()->firstOrCreate(
        ['slug' => 'trial'],
        ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true],
    );
    $secondClub->subscription()->create([
        'saas_plan_id' => $plan->id,
        'status' => 'active',
        'started_at' => now(),
    ]);
    $secondToken = $secondUser->createToken('agent-b', ['club:'.$secondClub->id])->plainTextToken;
    $currentClub->clear();
    $this->app['auth']->forgetGuards();

    $secondResponse = $this->withToken($secondToken)->postJson(route('api.club-computers.register'), [
        'device_name' => 'PC-07',
        'device_id' => 'shared-smbios-id',
    ])->assertCreated();

    expect($secondResponse->json('computer_id'))->not->toBe($firstResponse->json('computer_id'))
        ->and(DB::table('club_computers')->where('device_id', 'shared-smbios-id')->count())->toBe(2);
});
