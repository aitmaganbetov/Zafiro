<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /** @param array<string, mixed> $details */
    public function record(
        string $event,
        string $description,
        ?int $userId = null,
        ?Model $subject = null,
        array $details = [],
    ): ActivityLog {
        return ActivityLog::query()->create([
            'event' => $event,
            'description' => $description,
            'user_id' => $userId,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
