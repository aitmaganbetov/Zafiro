<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubMembership;
use Illuminate\Support\Facades\Storage;

class CurrentClub
{
    private ?Club $club = null;

    private ?ClubMembership $membership = null;

    private bool $utc = false;

    public function set(Club $club, ?ClubMembership $membership = null): void
    {
        $this->club = $club;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->club = null;
        $this->membership = null;
        $this->utc = false;
    }

    /** API responses keep UTC timestamps (their contract); pages use the club's zone. */
    public function useUtc(): void
    {
        $this->utc = true;
    }

    public function get(): ?Club
    {
        return $this->club;
    }

    public function membership(): ?ClubMembership
    {
        return $this->membership;
    }

    public function id(): ?int
    {
        return $this->club?->getKey();
    }

    /**
     * The club's own time zone for rules of local time (tariff days and hours), also in APIs —
     * unlike timezone(), which APIs keep at UTC for the times they return. UTC outside a club.
     */
    public function localTimezone(): string
    {
        $timezone = $this->club?->getAttributes()['timezone'] ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : config('app.timezone');
    }

    /** Time zone for showing times: the club's, or UTC outside a club (queue jobs, guests) and in APIs. */
    public function timezone(): string
    {
        $timezone = $this->utc ? null : ($this->club?->getAttributes()['timezone'] ?? null);

        return is_string($timezone) && $timezone !== '' ? $timezone : config('app.timezone');
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        if ($this->club?->settings !== null) {
            return $this->club->settings;
        }

        if ($this->club?->slug === 'legacy' && Storage::exists('settings.json')) {
            return json_decode(Storage::get('settings.json'), true) ?: [];
        }

        return [];
    }
}
