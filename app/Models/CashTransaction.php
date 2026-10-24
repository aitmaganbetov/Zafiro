<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['cash_shift_id', 'refund_of_id', 'user_id', 'type', 'amount', 'description', 'receipt_data'])]
class CashTransaction extends Model
{
    use BelongsToClub, UsesClubTimezone;

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'receipt_data' => 'array'];
    }

    /** POS sales are marked with this description (a marker, not translated). */
    public const POS_SALE = 'Продажа POS';

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    /** The refund of this income, when it was returned. */
    public function refund(): HasOne
    {
        return $this->hasOne(self::class, 'refund_of_id');
    }

    /**
     * What a refund of this income gives back: "sale" — goods to the stock, "top_up" — the amount
     * taken from the customer's balance. Null — not refundable (an expense, a guest session,
     * other income, a top-up made before refunds kept its customer).
     */
    public function refundKind(): ?string
    {
        if ($this->type !== self::TYPE_INCOME) {
            return null;
        }
        if ($this->description === self::POS_SALE && is_array($this->receipt_data['items'] ?? null)) {
            return 'sale';
        }

        return isset($this->receipt_data['top_up']['user_id']) ? 'top_up' : null;
    }
}
