<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A service sold at the cash register without stock (PlayStation by the hour, printing a page…). */
#[Fillable(['name', 'price', 'unit', 'is_active'])]
class ClubService extends Model
{
    use BelongsToClub, UsesClubTimezone;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
