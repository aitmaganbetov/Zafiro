<?php

namespace App\Services;

use App\Models\Club;
use App\Models\PlatformLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Writes the platform log: who (superadmin), what, about which club and object, from which IP. */
class PlatformLogger
{
    /**
     * $user is null for what the platform does on its own (the scheduler).
     *
     * @param  array<string, mixed>  $details
     */
    public function record(string $event, string $description, ?User $user, ?Club $club = null, ?Model $subject = null, array $details = []): PlatformLog
    {
        return PlatformLog::query()->create([
            'user_id' => $user?->getKey(),
            'club_id' => $club?->getKey(),
            'event' => $event,
            'description' => mb_substr($description, 0, 500),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details ?: null,
            'ip_address' => (string) (request()->ip() ?? '127.0.0.1'),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500) ?: null,
        ]);
    }
}
