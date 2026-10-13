<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'is_enabled', 'sort_order'])]
class Language extends Model
{
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'is_system' => 'boolean', 'sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }
}
