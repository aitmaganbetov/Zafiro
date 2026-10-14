<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class ProductCategory extends Model
{
    use BelongsToClub, UsesClubTimezone;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
