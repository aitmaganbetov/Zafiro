<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Money the platform paid a reseller out of their earnings (recorded by the superadmin). */
#[Fillable(['amount', 'note', 'created_by'])]
class ResellerPayout extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
