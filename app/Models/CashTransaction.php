<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['cash_shift_id', 'user_id', 'type', 'amount', 'description', 'receipt_data'])]
class CashTransaction extends Model
{
    use BelongsToClub;

    public const TYPE_INCOME = 'income';
    public const TYPE_EXPENSE = 'expense';

    protected function casts(): array { return ['amount' => 'decimal:2', 'receipt_data' => 'array']; }
    public function shift(): BelongsTo { return $this->belongsTo(CashShift::class, 'cash_shift_id'); }
}
