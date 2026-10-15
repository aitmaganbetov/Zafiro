<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A QR code shown on a PC's sign-in screen; only the hash of its code is stored. */
#[Fillable(['club_computer_id', 'code_hash', 'approved_user_id', 'approved_at', 'consumed_at', 'expires_at'])]
class AgentQrLogin extends Model
{
    use BelongsToClub, HasUuids;

    public const LIFETIME_MINUTES = 5;

    protected function casts(): array
    {
        return [
            'club_computer_id' => 'integer',
            'approved_user_id' => 'integer',
            'approved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(ClubComputer::class, 'club_computer_id');
    }

    public function isOpen(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
