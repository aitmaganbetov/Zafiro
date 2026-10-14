<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['url', 'events', 'is_active'])]
#[Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    use BelongsToClub, UsesClubTimezone;

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
