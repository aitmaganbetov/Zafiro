<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'role', 'discount'])]
class ClubMembership extends Model
{
    use BelongsToClub, UsesClubTimezone;

    protected function casts(): array
    {
        return ['discount' => 'decimal:2', 'notifications_seen_at' => 'datetime', 'can_manage_integrations' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canManageIntegrations(): bool
    {
        return $this->role === 'owner' || $this->can_manage_integrations;
    }

    public function balance(): HasOne
    {
        return $this->hasOne(MemberBalance::class, 'user_id', 'user_id');
    }
}
