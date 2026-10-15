<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'author_id', 'from_staff', 'body', 'club_computer_id'])]
class ChatMessage extends Model
{
    use BelongsToClub, UsesClubTimezone;

    public const MAX_LENGTH = 1000;

    /** Staff who answer in the chat; the others only read it. */
    public const ANSWERING_ROLES = ['cashier', 'operator'];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'author_id' => 'integer',
            'from_staff' => 'boolean',
            'club_computer_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(ClubComputer::class, 'club_computer_id');
    }
}
