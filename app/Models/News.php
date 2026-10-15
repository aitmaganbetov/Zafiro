<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A news item for the PC agent. Platform news has no club (written by the superadmin) and is
 * shown in every club; club news belongs to one club. Not club-scoped on purpose: queries
 * pick the owner explicitly with platform() / ofClub().
 */
#[Fillable(['title', 'body', 'is_published'])]
class News extends Model
{
    public const PAGE = 20;

    protected function casts(): array
    {
        return ['club_id' => 'integer', 'is_published' => 'boolean'];
    }

    public function scopePlatform(Builder $query): void
    {
        $query->whereNull('club_id');
    }

    public function scopeOfClub(Builder $query, int $clubId): void
    {
        $query->where('club_id', $clubId);
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path === null ? null : route('news-images.show', [$this, basename($this->image_path)]);
    }
}
