<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['club_zone_id', 'number', 'status', 'is_reserved', 'position_x', 'position_y', 'last_seen_at'])]
class ClubComputer extends Model
{
    use BelongsToClub;

    public const HEARTBEAT_TIMEOUT_SECONDS = 90;

    protected $attributes = ['status' => 'available', 'is_reserved' => false, 'position_x' => 5, 'position_y' => 5];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_reserved' => 'boolean',
            'position_x' => 'integer',
            'position_y' => 'integer',
            'last_seen_at' => 'datetime',
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

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ClubZone::class, 'club_zone_id');
    }
}
