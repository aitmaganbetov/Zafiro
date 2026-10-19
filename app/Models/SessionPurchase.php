<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A part of a paid customer session: a tariff, minutes or a started package (see SessionSettlement). */
#[Fillable(['session_id', 'user_id', 'kind', 'tariff_id', 'name', 'minutes', 'main_cents', 'bonus_cents', 'expires_at'])]
class SessionPurchase extends Model
{
    use BelongsToClub;

    public const KIND_TARIFF = 'tariff';

    public const KIND_MINUTES = 'minutes';

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'tariff_id' => 'integer', 'minutes' => 'integer', 'main_cents' => 'integer', 'bonus_cents' => 'integer', 'expires_at' => 'datetime'];
    }
}
