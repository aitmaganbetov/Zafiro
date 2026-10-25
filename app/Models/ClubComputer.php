<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['club_zone_id', 'number', 'device_name', 'display_name', 'device_id', 'status', 'is_reserved', 'position_x', 'position_y', 'last_seen_at', 'agent_state', 'agent_version', 'current_session_id', 'current_session_user_id', 'session_guest', 'session_ends_at', 'mac_address', 'local_ip', 'remote_ip', 'cpu_model', 'cpu_cores', 'ram_total_mb', 'gpu_model', 'os_version', 'hardware_reported_at'])]
#[Hidden(['agent_token_hash'])]
class ClubComputer extends Model
{
    use BelongsToClub, UsesClubTimezone;

    public const HEARTBEAT_TIMEOUT_SECONDS = 90;

    public const AGENT_STATES = ['idle', 'in_session', 'locked', 'shutting_down'];

    protected $attributes = ['status' => 'available', 'is_reserved' => false, 'position_x' => 5, 'position_y' => 5];

    /** Held by a booking now (set by pages that show the status, see ComputerReservations::heldComputerIds()). */
    public bool $bookedNow = false;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_reserved' => 'boolean',
            'position_x' => 'integer',
            'position_y' => 'integer',
            'current_session_user_id' => 'integer',
            'session_guest' => 'boolean',
            'last_seen_at' => 'datetime',
            'session_ends_at' => 'datetime',
            'agent_update_offered_at' => 'datetime',
            'power_command_at' => 'datetime',
            'staff_message_at' => 'datetime',
            'wake_requested_at' => 'datetime',
            'cpu_cores' => 'integer',
            'ram_total_mb' => 'integer',
            'hardware_reported_at' => 'datetime',
            'agent_token_issued_at' => 'datetime',
            'staff_user_id' => 'integer',
            'staff_until' => 'datetime',
        ];
    }

    /** A staff member is working on this PC (agent closed with the PIN or staff signed in). */
    public function hasStaffVisit(): bool
    {
        return $this->staff_until?->isFuture() === true;
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    /** The customer whose paid session runs on this PC (none for a guest session from the cash register). */
    public function sessionUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_session_user_id');
    }

    public function currentStatus(): string
    {
        if ($this->is_reserved || $this->bookedNow) {
            return 'reserved';
        }

        if ($this->status === 'offline') {
            return 'offline';
        }

        if ($this->last_seen_at === null) {
            return $this->status === 'available' ? 'offline' : $this->status;
        }

        return $this->last_seen_at->gt(now()->subSeconds(self::HEARTBEAT_TIMEOUT_SECONDS))
            ? 'online'
            : 'offline';
    }

    public function connectionStatus(): string
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subSeconds(self::HEARTBEAT_TIMEOUT_SECONDS))
            ? 'online'
            : 'offline';
    }

    /** A session bought for a customer (or for a guest at the cash register) that has not run out yet. */
    public function hasPaidSession(): bool
    {
        return $this->current_session_id !== null
            && ($this->current_session_user_id !== null || $this->session_guest === true)
            && $this->session_ends_at?->isFuture() === true;
    }

    public function displayName(): string
    {
        return $this->display_name ?: $this->device_name ?: sprintf('PC-%02d', $this->number);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(ComputerReservation::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ClubZone::class, 'club_zone_id');
    }
}
