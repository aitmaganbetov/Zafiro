<?php

namespace App\Models;

use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['language_id', 'group', 'key', 'key_hash', 'value'])]
class Translation extends Model
{
    use UsesClubTimezone;

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public static function hashKey(string $group, string $key): string
    {
        return sha1($group.'|'.$key);
    }
}
