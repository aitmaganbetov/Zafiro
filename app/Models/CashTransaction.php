<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cash_shift_id', 'user_id', 'type', 'amount', 'description', 'receipt_data'])]
class CashTransaction extends Model
{
    use BelongsToClub, UsesClubTimezone;

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'receipt_data' => 'array'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }
}
