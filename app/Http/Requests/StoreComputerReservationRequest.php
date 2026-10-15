<?php

namespace App\Http\Requests;

use App\Services\CurrentClub;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComputerReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Same format as customer accounts: +7 and ten digits.
        $digits = preg_replace('/\D+/', '', (string) $this->input('customer_phone', ''));
        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            $digits = substr($digits, 1);
        }

        $this->merge(['customer_phone' => $digits === '' ? null : '+7'.$digits]);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'club_computer_id' => ['required', 'integer', Rule::exists('club_computers', 'id')->where('club_id', app(CurrentClub::class)->id())],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'customer_name' => ['nullable', 'string', 'max:120', 'required_without:customer_phone'],
            'customer_phone' => ['nullable', 'string', 'regex:/^\+7\d{10}$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_name.required_without' => __('Укажите имя или телефон клиента.'),
            'customer_phone.regex' => __('Телефон — 10 цифр после +7.'),
        ];
    }
}
