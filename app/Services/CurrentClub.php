<?php

namespace App\Services;

use App\Models\Club;
use Illuminate\Support\Facades\Storage;

class CurrentClub
{
    private ?Club $club = null;

    public function set(Club $club): void
    {
        $this->club = $club;
    }

    public function clear(): void
    {
        $this->club = null;
    }

    public function get(): ?Club
    {
        return $this->club;
    }

    public function id(): ?int
    {
        return $this->club?->getKey();
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
