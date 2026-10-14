<?php

use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pcWithAgent(int $number, ?string $version): ClubComputer
{
    $zone = ClubZone::query()->firstOrCreate(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number]);
    $computer->forceFill(['agent_version' => $version])->save();

    return $computer;
}

function releaseAgent(string $version, ?string $url = 'https://zafiro.kz/downloads/ZafiroAgentSetup-1.2.0.exe'): void
{
    config(['club.agent' => ['latest_version' => $version, 'released_at' => '2026-10-15 09:00:00', 'download_url' => $url]]);
}

function noticeIds(User $user): array
{
    return collect(test()->actingAs($user)->getJson(route('notifications.index'))->assertOk()->json('items'))->pluck('id')->all();
}

test('owners are told about a new agent version while some PCs run an older one', function () {
    $owner = User::factory()->create();
    pcWithAgent(1, '1.1.9');
    pcWithAgent(2, '1.2.0');
    pcWithAgent(3, null);
    releaseAgent('1.2.0');

    $item = collect($this->actingAs($owner)->getJson(route('notifications.index'))->json('items'))->firstWhere('id', 'agent-update-1.2.0');

    expect($item)->not->toBeNull()
        ->and($item['title'])->toBe(__('Новая версия агента :version', ['version' => '1.2.0']))
        ->and($item['body'])->toContain('1')->toContain('2')
        ->and($item['url'])->toBe(route('club-computers.index'))
        ->and($item['unread'])->toBeTrue();
});

test('there is no notice when every PC is up to date or no release is configured', function () {
    $owner = User::factory()->create();
    pcWithAgent(1, '1.2.0');
    pcWithAgent(2, '1.3.0');

    expect(noticeIds($owner))->not->toContain('agent-update-1.2.0');

    releaseAgent('1.2.0');
    expect(noticeIds($owner))->not->toContain('agent-update-1.2.0');

    config(['club.agent.latest_version' => 'latest']);
    pcWithAgent(3, '0.9.0');
    expect(collect(noticeIds($owner))->filter(fn (string $id): bool => str_starts_with($id, 'agent-update')))->toBeEmpty();
});

test('versions are compared as numbers and unreadable ones count as old', function () {
    $owner = User::factory()->create();
    pcWithAgent(1, '1.10.0');
    releaseAgent('1.9.0');
    expect(noticeIds($owner))->not->toContain('agent-update-1.9.0');

    pcWithAgent(2, 'dev-build');
    expect(noticeIds($owner))->toContain('agent-update-1.9.0');
});

test('cashiers do not get the agent notice', function () {
    $cashier = User::factory()->create();
    ClubMembership::query()->where('user_id', $cashier->id)->update(['role' => 'cashier']);
    pcWithAgent(1, '1.0.0');
    releaseAgent('1.2.0');

    expect(noticeIds($cashier))->not->toContain('agent-update-1.2.0');
});

test('the PC list explains the diskless update and marks old agents', function () {
    $owner = User::factory()->create();
    pcWithAgent(1, '1.1.0');
    releaseAgent('1.2.0');

    $this->actingAs($owner)->get(route('club-computers.index'))->assertOk()
        ->assertSee(__('Бездисковая система — обновить один раз в образе:'))
        ->assertSee('href="https://zafiro.kz/downloads/ZafiroAgentSetup-1.2.0.exe"', false)
        ->assertSee(__('устарела'));
});

test('only an https download link is shown', function () {
    $owner = User::factory()->create();
    pcWithAgent(1, '1.1.0');
    releaseAgent('1.2.0', 'javascript:alert(1)');

    $this->actingAs($owner)->get(route('club-computers.index'))->assertOk()
        ->assertSee(__('Новая версия агента :version', ['version' => '1.2.0']))
        ->assertDontSee('javascript:alert(1)', false)
        ->assertDontSee(__('Скачать установщик'));
});
