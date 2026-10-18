<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A password recovery code sent to the user's Telegram (Telegram Gateway); only its hash is stored. */
#[Fillable(['user_id', 'code_hash', 'attempts', 'expires_at'])]
class PasswordRecoveryCode extends Model
{
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'attempts' => 'integer', 'expires_at' => 'datetime'];
    }
}
