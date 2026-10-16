<?php

namespace App\Models;

use App\Models\Concerns\UsesClubTimezone;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\CurrentClub;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'phone', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'telegram_chat_id', 'telegram_link_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    use UsesClubTimezone;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
            'blocked_at' => 'datetime',
            'telegram_linked_at' => 'datetime',
            'telegram_link_expires_at' => 'datetime',
        ];
    }

    /** Platform superadmin (not a club role): manages clubs, users, plans, languages of the whole platform. */
    public function isSuperadmin(): bool
    {
        return $this->is_superadmin === true;
    }

    /** Blocked by a superadmin: cannot sign in, sessions and API tokens are revoked. */
    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ClubMembership::class);
    }

    public function currentMembership(): HasOne
    {
        return $this->hasOne(ClubMembership::class)
            ->where('club_id', app(CurrentClub::class)->id() ?? 0);
    }
}
