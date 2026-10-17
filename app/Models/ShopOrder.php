<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Goods ordered in the PC agent and paid from the customer's main balance. */
#[Fillable(['user_id', 'club_computer_id', 'computer_name', 'items', 'amount', 'status'])]
class ShopOrder extends Model
{
    use BelongsToClub, UsesClubTimezone;

    public const STATUS_NEW = 'new';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'club_computer_id' => 'integer',
            'items' => 'array',
            'amount' => 'decimal:2',
            'handled_by' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** New orders of the current club: the badge in the menu. */
    public static function newCount(): int
    {
        return self::query()->where('status', self::STATUS_NEW)->count();
    }
}
