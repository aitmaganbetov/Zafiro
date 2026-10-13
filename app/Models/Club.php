<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use LogicException;

#[Fillable(['name', 'slug', 'settings'])]
class Club extends Model
{
    protected function casts(): array
    {
        return ['settings' => 'array', 'installation_key' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::creating(function (Club $club): void {
            if ($club->installation_key_hash !== null) {
                return;
            }

            $key = Str::random(64);
            $club->forceFill([
                'installation_key' => $key,
                'installation_key_hash' => hash('sha256', $key),
                'installation_key_prefix' => substr($key, 0, 12),
            ]);
        });

        static::updating(function (Club $club): void {
            if ($club->isDirty(['installation_key', 'installation_key_hash', 'installation_key_prefix'])) {
                throw new LogicException('A club installation key cannot be changed or removed.');
            }
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ClubMembership::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(ClubSubscription::class);
    }
}
