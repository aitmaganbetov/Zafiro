<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'section' => ['required', Rule::in(['club', 'finances', 'guests'])],
            'session_idle_timeout' => ['required_if:section,club', 'numeric', 'min:0', 'max:10080'],
            'pc_restart_after_session_end' => ['required_if:section,club', 'numeric', 'min:0', 'max:10080'],
            'session_auto_terminate_when_pc_unavailable' => ['required_if:section,club', 'numeric', 'min:0', 'max:10080'],
            'booking_interval' => ['required_if:section,club', 'numeric', 'min:0', 'max:10080'],
            'currency' => ['required_if:section,finances', Rule::in(['KZT', 'RUB', '$'])],
            'show_qr_code_on_login' => ['nullable', 'boolean'],
            'allow_guest_self_transfer' => ['nullable', 'boolean'],
            'allow_adding_friends' => ['nullable', 'boolean'],
            'allow_balance_transfer_to_friends' => ['nullable', 'boolean'],
        ];
    }
}
