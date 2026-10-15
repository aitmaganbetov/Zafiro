<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['requester_id', 'addressee_id', 'accepted_at'])]
class Friendship extends Model
{
    use BelongsToClub, UsesClubTimezone;

    protected function casts(): array
    {
        return ['requester_id' => 'integer', 'addressee_id' => 'integer', 'accepted_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    /** Friendships and requests the user takes part in. */
    public function scopeInvolving(Builder $query, int $userId): void
    {
        $query->where(fn (Builder $query) => $query->where('requester_id', $userId)->orWhere('addressee_id', $userId));
    }

    public function involves(int $userId): bool
    {
        return $this->requester_id === $userId || $this->addressee_id === $userId;
    }

    public function otherThan(int $userId): int
    {
        return $this->requester_id === $userId ? $this->addressee_id : $this->requester_id;
    }
}
