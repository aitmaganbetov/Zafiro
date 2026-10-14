<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['club_zone_id', 'number', 'device_name', 'display_name', 'device_id', 'status', 'is_reserved', 'position_x', 'position_y', 'last_seen_at', 'agent_state', 'agent_version', 'current_session_id', 'session_ends_at', 'mac_address', 'local_ip', 'remote_ip', 'cpu_model', 'cpu_cores', 'ram_total_mb', 'gpu_model', 'os_version', 'hardware_reported_at'])]
class ClubComputer extends Model
{
    use BelongsToClub, UsesClubTimezone;

    public const HEARTBEAT_TIMEOUT_SECONDS = 90;

    public const AGENT_STATES = ['idle', 'in_session', 'locked', 'shutting_down'];

    protected $attributes = ['status' => 'available', 'is_reserved' => false, 'position_x' => 5, 'position_y' => 5];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_reserved' => 'boolean',
            'position_x' => 'integer',
            'position_y' => 'integer',
            'last_seen_at' => 'datetime',
            'session_ends_at' => 'datetime',
            'cpu_cores' => 'integer',
            'ram_total_mb' => 'integer',
            'hardware_reported_at' => 'datetime',
        ];
    }

    public function currentStatus(): string
    {
        if ($this->is_reserved) {
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

    public function displayName(): string
    {
        return $this->display_name ?: $this->device_name ?: sprintf('PC-%02d', $this->number);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ClubZone::class, 'club_zone_id');
    }
}
