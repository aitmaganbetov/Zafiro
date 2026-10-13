<?php

namespace App\Models\Concerns;

use App\Models\Club;
use App\Services\CurrentClub;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToClub
{
    protected static function bootBelongsToClub(): void
    {
        static::addGlobalScope('club', function (Builder $query): void {
            $clubId = app(CurrentClub::class)->id();

            if ($clubId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($query->getModel()->qualifyColumn('club_id'), $clubId);
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('club_id') !== null) {
                return;
            }

            $clubId = app(CurrentClub::class)->id();

            if ($clubId === null) {
                throw new LogicException('A current club is required to create tenant data.');
            }

            $model->setAttribute('club_id', $clubId);
        });
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}