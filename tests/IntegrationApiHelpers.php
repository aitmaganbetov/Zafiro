<?php

use App\Models\ApiKey;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\SaasPlan;
use App\Models\User;
use App\Services\CurrentClub;
use App\Services\Integrations\ApiKeyManager;

/**
 * A club with an active subscription and an owner, without changing the current club.
 *
 * @return array{0: Club, 1: User}
 */
function integrationClub(string $slug = 'other-club'): array
{
    $current = app(CurrentClub::class);
    $previous = $current->get();
    $club = Club::query()->create(['name' => "Club {$slug}", 'slug' => $slug]);
    $current->set($club);

    try {
        $owner = User::factory()->create();
        $plan = SaasPlan::query()->firstOrCreate(['slug' => 'trial'], ['name' => 'Trial', 'trial_days' => 14, 'is_active' => true]);
        $club->subscription()->create(['saas_plan_id' => $plan->id, 'status' => 'active', 'started_at' => now()]);
    } finally {
        $previous === null ? $current->clear() : $current->set($previous);
    }

    return [$club, $owner];
}

/** The legacy club of the test database and an owner of it. */
function legacyClubOwner(): array
{
    $club = Club::query()->where('slug', 'legacy')->firstOrFail();
    app(CurrentClub::class)->set($club);

    return [$club, User::factory()->create()];
}

/**
 * Issue a key for $club as $user and return [key, plain secret].
 *
 * @param  list<string>  $scopes
 * @param  array<string, mixed>  $data
 * @return array{0: ApiKey, 1: string}
 */
function issueApiKey(Club $club, User $user, array $scopes = ['products:read'], array $data = []): array
{
    $current = app(CurrentClub::class);
    $previous = $current->get();
    $current->set($club, ClubMembership::query()->withoutGlobalScope('club')->where('club_id', $club->id)->where('user_id', $user->id)->first());

    try {
        return app(ApiKeyManager::class)->create($user, ['name' => 'Test key', 'scopes' => $scopes, ...$data]);
    } finally {
        $previous === null ? $current->clear() : $current->set($previous);
    }
}
