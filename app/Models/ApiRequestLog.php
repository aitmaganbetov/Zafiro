<?php

namespace App\Models;

use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Model;

class ApiRequestLog extends Model
{
    use UsesClubTimezone;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
