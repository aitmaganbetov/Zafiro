<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ClubMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * A failed sign-in is logged in every club of the account it targeted. Attempts
     * for unknown phone numbers have no user to attach, so they go to the system log.
     *
     * @param  array<string, mixed>  $details
     */
    public function recordFailedLogin(string $event, string $phone, string $reason, array $details = []): void
    {
        $user = User::query()->where('phone', $phone)->first();
        $clubIds = $user === null
            ? collect()
            : ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->getKey())->pluck('club_id');

        if ($clubIds->isEmpty()) {
            Log::warning('Failed sign-in without a club account', [
                'event' => $event,
                'phone' => $phone,
                'reason' => $reason,
                'user_id' => $user?->getKey(),
                'ip' => request()->ip(),
            ]);

            return;
        }

        $description = $event === 'auth.api_login_failed'
            ? __('Неудачный вход через API: :user', ['user' => $user->name])
            : __('Неудачный вход: :user', ['user' => $user->name]);

        DB::transaction(fn () => $clubIds->each(fn (int $clubId) => $this->record(
            $event,
            $description,
            $user->getKey(),
            $user,
            ['reason' => $reason, ...$details],
            $clubId,
        )));
    }
}
