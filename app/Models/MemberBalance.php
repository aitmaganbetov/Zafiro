<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'balance', 'bonus_balance'])]
class MemberBalance extends Model
{
    use BelongsToClub, UsesClubTimezone;

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'bonus_balance' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
