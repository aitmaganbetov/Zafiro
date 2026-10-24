<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Unused time of a tariff with a usage limit: the customer starts it later, until it expires. */
#[Fillable(['user_id', 'tariff_id', 'name', 'minutes_left', 'expires_at'])]
class TimePackage extends Model
{
    use BelongsToClub, UsesClubTimezone;

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'tariff_id' => 'integer', 'minutes_left' => 'integer', 'expires_at' => 'datetime'];
    }

    public function tariff(): BelongsTo
    {
        // A pass of a deleted tariff still works, within that tariff's zones and validity.
        return $this->belongsTo(Tariff::class)->withTrashed();
    }
}
