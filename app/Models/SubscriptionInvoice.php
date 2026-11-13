<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bill for N months of a plan for all the club's PCs, paid by Kaspi and confirmed by the superadmin. */
#[Fillable(['saas_plan_id', 'months', 'computers', 'price_per_computer', 'discount_percent', 'amount', 'reseller_id', 'reseller_percent', 'created_by'])]
class SubscriptionInvoice extends Model
{
    use BelongsToClub, UsesClubTimezone;

    public const MONTHS = [1, 3, 6, 12];

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'computers' => 'integer',
            'price_per_computer' => 'decimal:2',
            'discount_percent' => 'integer',
            'amount' => 'decimal:2',
            'reseller_percent' => 'decimal:2',
            'reseller_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'period_ends_at' => 'datetime',
        ];
    }

    /** What the club writes in the payment comment so the money can be matched to the bill. */
    public function number(): string
    {
        return 'ZF-'.str_pad((string) $this->getKey(), 5, '0', STR_PAD_LEFT);
    }

    /** The reseller of the club when the bill was opened: they earn a percent of it. */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }
}
