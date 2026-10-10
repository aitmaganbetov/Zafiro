<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description', 'capacity', 'icon', 'theme', 'status', 'is_featured', 'sort_order', 'position_x', 'position_y', 'width', 'height', 'rotation'])]
class ClubZone extends Model
{
    protected $attributes = ['icon' => 'desktop', 'theme' => 'neutral', 'status' => 'available', 'is_featured' => false, 'sort_order' => 0, 'position_x' => 20, 'position_y' => 20, 'width' => 320, 'height' => 180, 'rotation' => 0];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'is_featured' => 'boolean', 'sort_order' => 'integer', 'position_x' => 'integer', 'position_y' => 'integer', 'width' => 'integer', 'height' => 'integer', 'rotation' => 'integer'];
    }

    public function computers(): HasMany
    {
        return $this->hasMany(ClubComputer::class)->orderBy('number');
    }

    public function tariffs(): BelongsToMany
    {
        return $this->belongsToMany(Tariff::class);
    }
}
