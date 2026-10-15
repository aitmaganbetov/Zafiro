<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A task of the club for a day; one shared list: done by one staff member, done for all. */
#[Fillable(['title', 'due_on'])]
class TodoTask extends Model
{
    use BelongsToClub, UsesClubTimezone;

    protected function casts(): array
    {
        // due_on stays a plain Y-m-d string: a calendar day of the club, not a moment in time.
        return ['done_at' => 'datetime', 'created_by' => 'integer', 'done_by' => 'integer'];
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
