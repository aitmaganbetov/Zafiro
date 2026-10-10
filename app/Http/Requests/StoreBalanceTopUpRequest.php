<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBalanceTopUpRequest extends FormRequest
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
            'user_id' => ['required', 'integer', Rule::exists((new User)->getTable(), 'id')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'balance_type' => ['required', Rule::in(['balance', 'bonus_balance'])],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'qr'])],
        ];
    }
}
