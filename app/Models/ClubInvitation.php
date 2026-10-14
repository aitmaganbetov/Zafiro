<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'])]
class ClubInvitation extends Model
{
    use BelongsToClub, UsesClubTimezone;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
