<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ActivityLogger
{
    /**
     * Every entry names the acting user and the client IP address.
     * $clubId overrides the current club for events that belong to another club.
     *
     * @param  array<string, mixed>  $details
     */
    public function record(
        string $event,
        string $description,
        int $userId,
        ?Model $subject = null,
        array $details = [],
        ?int $clubId = null,
    ): ActivityLog {
        $ip = request()->ip();

        if ($ip === null || $ip === '') {
            throw new LogicException('Activity log entries require the client IP address.');
        }

        $log = new ActivityLog([
            'event' => $event,
            'description' => $description,
            'user_id' => $userId,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details ?: null,
            'ip_address' => $ip,
            'user_agent' => request()->userAgent(),
        ]);

        if ($clubId !== null) {
            $log->club_id = $clubId;
        }

        $log->save();

        return $log;
    }
}
