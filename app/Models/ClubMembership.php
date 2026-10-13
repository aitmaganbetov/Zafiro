<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'role', 'discount'])]
class ClubMembership extends Model
{
    use BelongsToClub;

    protected function casts(): array
    {
        return ['discount' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(MemberBalance::class, 'user_id', 'user_id');
    }
}
