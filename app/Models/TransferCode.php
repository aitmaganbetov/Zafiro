<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A code from Telegram confirming one transfer to a friend (that friend, that amount); only its hash is stored. */
#[Fillable(['user_id', 'friendship_id', 'amount_cents', 'code_hash', 'attempts', 'expires_at'])]
class TransferCode extends Model
{
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'friendship_id' => 'integer',
            'amount_cents' => 'integer',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
