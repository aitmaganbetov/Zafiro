<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reseller of the platform: clubs that entered their code are theirs, pay the subscription with
 * their discount, and bring them a percent of each payment (the superadmin sets both).
 */
#[Fillable(['code', 'name'])]
class Reseller extends Model
{
    /** Latin letters, digits and dashes, as typed in the club settings. */
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9-]{2,31}$/';

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'discount_percent' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Codes are compared in lower case: "Arman" and "arman" are one code. */
    public static function normalizeCode(?string $code): string
    {
        return mb_strtolower(trim((string) $code));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clubs(): HasMany
    {
        return $this->hasMany(Club::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class)->withoutGlobalScope('club');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(ResellerPayout::class);
    }
}
