<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\ClubZone;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\CurrentClub;
use App\Services\Monitoring\TelegramAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const BOT_TOKEN = '123456789:AAFakeBotTokenForTestsOnly_abcdefghij';

beforeEach(function () {
    $this->metricsPath = storage_path('framework/testing/metrics-'.uniqid());
    config(['monitoring.requests_path' => $this->metricsPath]);
});

afterEach(function () {
    File::deleteDirectory($this->metricsPath);
});

function monitoringAdmin(): User
{
    $user = User::factory()->superadmin()->create();
    ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->id)->delete();

    return $user;
}

function monitoringClub(): Club
{
    return Club::query()->findOrFail(app(CurrentClub::class)->id());
}

/** Alerts go to a fake bot; returns the texts sent. */
function fakeAlertBot(): void
{
    PlatformSetting::write(TelegramAlerts::SETTING, ['token' => Crypt::encryptString(BOT_TOKEN), 'chat_id' => '42', 'locale' => 'ru'], null);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
}

/** @return list<string> */
function sentAlerts(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0])
        ->filter(fn (HttpRequest $request) => str_ends_with($request->url(), '/sendMessage'))
        ->map(fn (HttpRequest $request) => $request['text'])->values()->all();
}

function addComputers(int $count, bool $online, bool $busy = false): void
{
    $zone = ClubZone::query()->firstOrCreate(['name' => 'Hall']);
    $start = (int) ClubComputer::query()->max('number');
    for ($i = 1; $i <= $count; $i++) {
        ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $start + $i])->forceFill([
            'last_seen_at' => $online ? now() : now()->subHour(),
            'current_session_id' => $busy ? 'session-'.($start + $i) : null,
            'session_guest' => $busy,
            'session_ends_at' => $busy ? now()->addHour() : null,
        ])->save();
    }
}

test('requests are counted per channel and club in minute files, then folded into the database', function () {
    config(['monitoring.record_requests' => true]);
    $club = monitoringClub();

    $this->get(route('auth.show-login'))->assertOk();
    $this->postJson(route('api.agent.heartbeat'))->assertUnauthorized();
    $this->getJson('/api/v1/me')->assertUnauthorized();
    expect(glob($this->metricsPath.'/requests-*.log'))->toHaveCount(1)
        ->and(DB::table('request_metrics')->count())->toBe(0);

    // A minute still running is not read.
    $this->artisan('monitoring:collect')->assertSuccessful();
    expect(DB::table('request_metrics')->count())->toBe(0);

    $this->travel(1)->minutes();
    $this->artisan('monitoring:collect')->assertSuccessful();

    expect(DB::table('request_metrics')->pluck('requests', 'channel')->all())->toEqual(['panel' => 1, 'agent' => 1, 'api' => 1])
        ->and(DB::table('request_metrics')->sum('errors'))->toEqual(0)
        ->and(glob($this->metricsPath.'/requests-*.log'))->toBe([])
        ->and(DB::table('server_metrics')->count())->toBe(2);

    // Lines of a club's agent count towards the club's load.
    $minute = intdiv(now()->getTimestamp(), 60);
    file_put_contents($this->metricsPath.'/requests-'.$minute.'.log', "agent\t{$club->id}\t12\t200\nagent\t{$club->id}\t30\t500\napi\t999999\t5\t200\n");
    $this->travel(1)->minutes();
    $this->artisan('monitoring:collect')->assertSuccessful();

    expect(DB::table('club_request_metrics')->where('club_id', $club->id)->where('minute', $minute)->value('agent_requests'))->toEqual(2)
        ->and(DB::table('club_request_metrics')->where('club_id', 999999)->exists())->toBeFalse()
        ->and(DB::table('request_metrics')->where('minute', $minute)->where('channel', 'agent')->first())
        ->requests->toEqual(2)->errors->toEqual(1)->duration_ms->toEqual(42);
});

test('an alert is sent once when a problem starts and once when it is over', function () {
    fakeAlertBot();
    DB::table('jobs')->insert(array_fill(0, 100, ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]));

    $this->artisan('monitoring:collect')->assertSuccessful();
    $this->travel(1)->minutes();
    $this->artisan('monitoring:collect')->assertSuccessful();
    expect(sentAlerts())->toHaveCount(1)
        ->and(sentAlerts()[0])->toContain('Копится очередь: 100 заданий');

    DB::table('jobs')->delete();
    $this->travel(1)->minutes();
    $this->artisan('monitoring:collect')->assertSuccessful();
    expect(sentAlerts())->toHaveCount(2)
        ->and(sentAlerts()[1])->toContain('Очередь разобрана');
});

test('a high share of 5xx errors is reported', function () {
    fakeAlertBot();
    $minute = intdiv(now()->getTimestamp(), 60);
    DB::table('request_metrics')->insert(['minute' => $minute - 1, 'channel' => 'panel', 'requests' => 100, 'errors' => 20, 'duration_ms' => 1000]);

    $this->artisan('monitoring:collect')->assertSuccessful();

    expect(sentAlerts())->toHaveCount(1)->and(sentAlerts()[0])->toContain('Ошибки 5xx: 20%');
});

test('requests notice a silent scheduler, and the scheduler reports it is back', function () {
    fakeAlertBot();
    config(['monitoring.record_requests' => true]);
    DB::table('server_metrics')->insert(['minute' => intdiv(now()->getTimestamp(), 60) - 10]);

    $this->get(route('auth.show-login'))->assertOk();
    $this->get(route('auth.show-login'))->assertOk();
    expect(sentAlerts())->toHaveCount(1)->and(sentAlerts()[0])->toContain('Планировщик молчит 10 мин');

    $this->artisan('monitoring:collect')->assertSuccessful();
    expect(sentAlerts())->toHaveCount(2)->and(sentAlerts()[1])->toContain('Планировщик снова работает');
});

test('club snapshots feed the heat map, and a club whose PCs all go offline is reported', function () {
    fakeAlertBot();
    $club = monitoringClub();
    ClubSubscription::query()->updateOrCreate(['club_id' => $club->id], ['status' => 'active', 'current_period_ends_at' => null]);
    addComputers(2, online: true, busy: true);
    addComputers(2, online: true);

    $this->artisan('monitoring:clubs')->assertSuccessful();
    expect(DB::table('club_load_snapshots')->where('club_id', $club->id)->first())
        ->computers->toEqual(4)->online->toEqual(4)->busy->toEqual(2);

    $this->travel(5)->minutes();
    ClubComputer::query()->update(['last_seen_at' => now()->subHour()]);
    $this->artisan('monitoring:clubs')->assertSuccessful();
    expect(sentAlerts())->toHaveCount(1)->and(sentAlerts()[0])->toContain('все ПК ушли офлайн');

    // The clubs page compares with a quarter of an hour ago.
    $this->travel(7)->minutes();
    $this->actingAs(monitoringAdmin())->get(route('superadmin.clubs.show', $club))->assertOk()
        ->assertSee('Загрузка по часам')->assertSee('занято 50%');
    $this->get(route('superadmin.clubs.index'))->assertOk()->assertSee('Требуют внимания')->assertSee('Возможно, у клуба пропал интернет');

    $this->travel(5)->minutes();
    ClubComputer::query()->update(['last_seen_at' => now()]);
    $this->artisan('monitoring:clubs')->assertSuccessful();
    expect(sentAlerts())->toHaveCount(2)->and(sentAlerts()[1])->toContain('снова в сети');
});

test('the clubs table shows load and activity, sorts by every column and lists subscriptions about to end', function () {
    $club = monitoringClub();
    ClubSubscription::query()->updateOrCreate(['club_id' => $club->id], ['status' => 'active', 'current_period_ends_at' => now()->addDays(2)]);
    addComputers(3, online: true, busy: true);
    addComputers(1, online: false);
    DB::table('club_request_metrics')->insert(['club_id' => $club->id, 'minute' => intdiv(now()->getTimestamp(), 60) - 1, 'agent_requests' => 150, 'api_requests' => 0]);

    $admin = monitoringAdmin();
    $this->actingAs($admin)->get(route('superadmin.clubs.index'))->assertOk()
        ->assertSee('Подписка заканчивается')
        ->assertSee('3 / 4')
        ->assertSee('75%')
        ->assertSee('10')
        ->assertSee('ещё 2 дня');

    foreach (['name', 'online', 'busy', 'agent_requests', 'api_requests', 'last_login', 'sessions', 'webhooks', 'ends'] as $sort) {
        $this->get(route('superadmin.clubs.index', ['sort' => $sort, 'direction' => 'desc', 'search' => $club->name]))->assertOk()->assertSee($club->name);
    }
    $this->get(route('superadmin.clubs.index', ['sort' => 'password']))->assertSessionHasErrors('sort');
});

test('the server page shows the machine, history, channels and the scheduler state', function () {
    $minute = intdiv(now()->getTimestamp(), 60);
    $this->actingAs(monitoringAdmin())->get(route('superadmin.dashboard'))->assertOk()->assertSee('Планировщик ещё не запускался');

    DB::table('server_metrics')->insert(['minute' => $minute - 1, 'load_1' => 0.5, 'memory_total_mb' => 1000, 'memory_used_mb' => 500]);
    DB::table('request_metrics')->insert(['minute' => $minute - 1, 'channel' => 'agent', 'requests' => 300, 'errors' => 3, 'duration_ms' => 6000]);

    $this->get(route('superadmin.dashboard'))->assertOk()
        ->assertSee('Планировщик работает')
        ->assertSee('Агенты ПК')
        ->assertSee('<polyline', false);
    $this->get(route('superadmin.dashboard', ['period' => '7d']))->assertOk()->assertSee('Запросов в минуту');
    $this->get(route('superadmin.dashboard', ['period' => ['x']]))->assertOk();

    DB::table('server_metrics')->insert(['minute' => $minute - 30]);
    DB::table('server_metrics')->where('minute', $minute - 1)->delete();
    $this->get(route('superadmin.dashboard'))->assertOk()->assertSee('Планировщик молчит 30 минут');
});

test('the alerts bot is connected with its token kept encrypted and out of the session', function () {
    Http::fake([
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1]]),
        'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [['update_id' => 1, 'message' => ['chat' => ['id' => -100500]]]]]),
        'api.telegram.org/*/sendMessage' => Http::response(['ok' => true]),
    ]);
    $admin = monitoringAdmin();

    $this->actingAs(User::factory()->create())->post(route('superadmin.telegram.alerts.update'), ['bot_token' => BOT_TOKEN])->assertForbidden();
    $this->actingAs($admin)->post(route('superadmin.telegram.alerts.update'), ['bot_token' => 'not-a-token'])
        ->assertSessionHasErrors('bot_token')->assertSessionMissing('_old_input.bot_token');
    $this->post(route('superadmin.telegram.alerts.update'), ['bot_token' => BOT_TOKEN])->assertSessionHasNoErrors();

    $stored = PlatformSetting::query()->findOrFail(TelegramAlerts::SETTING)->value;
    expect($stored['chat_id'])->toBe('-100500')
        ->and(json_encode($stored))->not->toContain(BOT_TOKEN)
        ->and(app(TelegramAlerts::class)->settings()['token'])->toBe(BOT_TOKEN);
    $this->get(route('superadmin.telegram.show'))->assertOk()->assertSee('-100500')->assertDontSee(BOT_TOKEN);

    $this->post(route('superadmin.telegram.alerts.test'))->assertSessionHasNoErrors();
    $this->delete(route('superadmin.telegram.alerts.destroy'))->assertSessionHasNoErrors();
    expect(app(TelegramAlerts::class)->configured())->toBeFalse();
});

test('a bot that nobody wrote to asks for /start', function () {
    Http::fake([
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1]]),
        'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => []]),
    ]);

    $this->actingAs(monitoringAdmin())->post(route('superadmin.telegram.alerts.update'), ['bot_token' => BOT_TOKEN])
        ->assertSessionHasErrors('bot_token');
    expect(app(TelegramAlerts::class)->configured())->toBeFalse();
});

test('old snapshots are pruned', function () {
    $club = monitoringClub();
    $old = intdiv(now()->subDays(31)->getTimestamp(), 60);
    DB::table('server_metrics')->insert([['minute' => $old], ['minute' => $old + 50000]]);
    DB::table('request_metrics')->insert(['minute' => $old, 'channel' => 'panel', 'requests' => 1, 'errors' => 0, 'duration_ms' => 1]);
    DB::table('club_load_snapshots')->insert(['club_id' => $club->id, 'captured_at' => now()->subDays(31), 'weekday' => 1, 'hour' => 1, 'computers' => 1, 'online' => 1, 'busy' => 0]);
    DB::table('club_request_metrics')->insert(['club_id' => $club->id, 'minute' => intdiv(now()->subDays(3)->getTimestamp(), 60), 'agent_requests' => 1]);

    $this->artisan('monitoring:prune')->assertSuccessful();

    expect(DB::table('server_metrics')->count())->toBe(1)
        ->and(DB::table('request_metrics')->count())->toBe(0)
        ->and(DB::table('club_load_snapshots')->count())->toBe(0)
        ->and(DB::table('club_request_metrics')->count())->toBe(0);
});
