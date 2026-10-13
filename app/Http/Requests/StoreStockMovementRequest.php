<?php

namespace App\Http\Requests;

use App\Models\StockMovement;
use App\Services\CurrentClub;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockMovementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('club_id', app(CurrentClub::class)->id())],
            'type' => ['required', Rule::in([
                StockMovement::TYPE_RECEIPT,
                StockMovement::TYPE_WRITE_OFF,
            ])],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'product_id' => 'товар',
            'type' => 'тип операции',
            'quantity' => 'количество',
            'note' => 'комментарий',
        ];
    }
}
