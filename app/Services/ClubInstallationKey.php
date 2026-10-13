<?php

namespace App\Services;

use App\Models\Club;

class ClubInstallationKey
{
    public function resolve(string $key): ?Club
    {
        $hash = hash('sha256', $key);

        return Club::query()
            ->where('installation_key_prefix', substr($key, 0, 12))
            ->get()
            ->first(fn (Club $club): bool => is_string($club->installation_key_hash)
                && hash_equals($club->installation_key_hash, $hash));
    }
}