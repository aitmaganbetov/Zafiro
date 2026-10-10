<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $digits = preg_replace('/\D+/', '', (string) $this->input('phone', ''));

        if (strlen($digits) === 11 && str_starts_with($digits, '7')) {
            $digits = substr($digits, 1);
        }

        $this->merge(['phone' => '+7'.$digits]);
    }

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
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+7\d{10}$/', Rule::unique((new User)->getTable(), 'phone')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique((new User)->getTable(), 'email')],
            'role' => ['required', Rule::in(['admin', 'cashier', 'manager', 'operator'])],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'balance' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'bonus_balance' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
