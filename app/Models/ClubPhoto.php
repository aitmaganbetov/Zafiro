<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A photo of the club for the guest app; public by its random file name. */
#[Fillable(['path', 'sort_order'])]
class ClubPhoto extends Model
{
    use BelongsToClub;

    /** Photos a club can have. */
    public const LIMIT = 10;

    public function url(): string
    {
        return route('club-photos.show', [$this->club_id, basename($this->path)]);
    }
}
