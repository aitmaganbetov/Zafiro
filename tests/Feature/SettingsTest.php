<?php

use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('club settings can be saved and reused on the next request', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->actingAs($user);

    Storage::delete('settings.json');

    $response = $this->post('/settings/save', [
        'section' => 'club',
        'session_idle_timeout' => 20,
        'pc_restart_after_session_end' => 0.75,
        'session_auto_terminate_when_pc_unavailable' => 10,
        'booking_interval' => 40,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'Настройки сохранены');

    $settings = app(CurrentClub::class)->get()->fresh()->settings;

    expect($settings['club']['session_idle_timeout'])->toBe(20);
    expect($settings['club']['pc_restart_after_session_end'])->toBe(0.75);
    expect($settings['club']['session_auto_terminate_when_pc_unavailable'])->toBe(10);
    expect($settings['club']['booking_interval'])->toBe(40);
});

test('the PC action after a session ends is saved and only known actions are accepted', function () {
    $this->actingAs(User::factory()->create());
    $fields = ['section' => 'club', 'session_idle_timeout' => 15, 'pc_restart_after_session_end' => 2, 'session_auto_terminate_when_pc_unavailable' => 5, 'booking_interval' => 30];

    $this->post('/settings/save', [...$fields, 'pc_action_after_session_end' => 'shutdown'])->assertRedirect()->assertSessionHasNoErrors();
    expect(app(CurrentClub::class)->get()->fresh()->settings['club'])
        ->pc_action_after_session_end->toBe('shutdown')
        ->pc_restart_after_session_end->toBe(2);
    $this->get(route('settings.club'))->assertOk()->assertSee('value="shutdown" selected', false);

    $this->post('/settings/save', [...$fields, 'pc_action_after_session_end' => 'format_c'])->assertSessionHasErrors('pc_action_after_session_end');
    expect(app(CurrentClub::class)->get()->fresh()->settings['club']['pc_action_after_session_end'])->toBe('shutdown');
});

test('finance currency setting can be selected', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->actingAs($user);

    Storage::delete('settings.json');

    foreach (['KZT', 'RUB', '$'] as $currency) {
        $this->post('/settings/save', [
            'section' => 'finances',
            'currency' => $currency,
        ])->assertRedirect();

        $settings = app(CurrentClub::class)->get()->fresh()->settings;

        expect($settings['finances']['currency'])->toBe($currency);
    }
});

test('guest login QR code setting is saved as a boolean', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->actingAs($user);

    Storage::delete('settings.json');

    foreach (['1' => true, '0' => false] as $value => $expected) {
        $this->post('/settings/save', [
            'section' => 'guests',
            'show_qr_code_on_login' => $value,
        ])->assertRedirect();

        $settings = app(CurrentClub::class)->get()->fresh()->settings;

        expect($settings['guests']['show_qr_code_on_login'])->toBe($expected);
    }
});

test('guest self transfer setting is saved as a boolean', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->actingAs($user);

    Storage::delete('settings.json');

    foreach (['1' => true, '0' => false] as $value => $expected) {
        $this->post('/settings/save', [
            'section' => 'guests',
            'allow_guest_self_transfer' => $value,
        ])->assertRedirect();

        $settings = app(CurrentClub::class)->get()->fresh()->settings;

        expect($settings['guests']['allow_guest_self_transfer'])->toBe($expected);
    }
});

test('adding friends setting is saved as a boolean', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->actingAs($user);

    Storage::delete('settings.json');

    foreach (['1' => true, '0' => false] as $value => $expected) {
        $this->post('/settings/save', [
            'section' => 'guests',
            'allow_adding_friends' => $value,
        ])->assertRedirect();

        $settings = app(CurrentClub::class)->get()->fresh()->settings;

        expect($settings['guests']['allow_adding_friends'])->toBe($expected);
    }
});

test('balance transfer to friends setting is saved as a boolean', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->actingAs($user);

    Storage::delete('settings.json');

    foreach (['1' => true, '0' => false] as $value => $expected) {
        $this->post('/settings/save', [
            'section' => 'guests',
            'allow_balance_transfer_to_friends' => $value,
        ])->assertRedirect();

        $settings = app(CurrentClub::class)->get()->fresh()->settings;

        expect($settings['guests']['allow_balance_transfer_to_friends'])->toBe($expected);
    }
});

test('API settings page displays every setting with saved values', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->actingAs($user);

    Storage::put('settings.json', json_encode([
        'finances' => [
            'currency' => 'RUB',
        ],
        'guests' => [
            'allow_adding_friends' => true,
        ],
    ]));

    $this->get('/settings/api')
        ->assertSuccessful()
        ->assertViewHas('settings', function (array $settings): bool {
            return $settings['club']['booking_interval'] === 30
                && $settings['finances']['currency'] === 'RUB'
                && $settings['guests']['show_qr_code_on_login'] === false
                && $settings['guests']['allow_guest_self_transfer'] === false
                && $settings['guests']['allow_adding_friends'] === true
                && $settings['guests']['allow_balance_transfer_to_friends'] === false;
        })
        ->assertViewHas('endpoints', fn (array $endpoints): bool => count($endpoints) === 24)
        ->assertViewHas('productEndpoint', fn (array $endpoint): bool => $endpoint['url'] === route('api.products.index'));
});

test('each setting has its own public JSON API endpoint', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Storage::put('settings.json', json_encode([
        'guests' => [
            'allow_adding_friends' => true,
        ],
    ]));

    $this->getJson('/api/settings/guests/allow_adding_friends')
        ->assertSuccessful()
        ->assertJson([
            'ip' => 'localhost',
            'setting' => 'guests.allow_adding_friends',
            'name' => 'Возможность добавления в друзья',
            'value' => true,
        ]);

    $this->getJson('/api/settings/guests/unknown_setting')
        ->assertNotFound();
});

test('the settings menu lists the sections of the open page as anchors', function () {
    $this->actingAs(User::factory()->create());

    $club = $this->get(route('settings.club'))->assertOk();
    // Each section is its own card.
    expect(substr_count($club->getContent(), 'rounded-2xl border border-gray-200 dark:border-gray-800 p-6'))->toBe(8);
    $club->assertSee('data-settings-anchors', false)
        ->assertSeeInOrder(['href="#club-sessions"', 'href="#agent-settings"', 'href="#agent-pin"', 'id="club-sessions"', 'id="agent-pin"'], false);
    $this->get(route('settings.finances'))->assertOk()->assertSee('href="#loyalty"', false)->assertSee('id="loyalty"', false)
        ->assertDontSee('href="#club-sessions"', false);
    $this->get(route('settings.guests'))->assertOk()->assertDontSee('data-settings-anchors', false);
});
