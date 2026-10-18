<?php

namespace App\Http\Requests;

use App\Services\LoyaltyProgram;
use App\Services\TopUpBonus;
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
            'pc_action_after_session_end' => ['sometimes', Rule::in(['none', 'restart', 'shutdown'])],
            'pc_restart_after_session_end' => ['required_if:section,club', 'numeric', 'min:0', 'max:10080'],
            'session_auto_terminate_when_pc_unavailable' => ['required_if:section,club', 'numeric', 'min:0', 'max:10080'],
            'booking_interval' => ['required_if:section,club', 'numeric', 'min:0', 'max:10080'],
            'max_cashiers_on_shift' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'max_operators_on_shift' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'currency' => ['required_if:section,finances', Rule::in(['KZT', 'RUB', '$'])],
            'loyalty_enabled' => ['nullable', 'boolean'],
            'loyalty_levels' => ['nullable', 'array', 'max:'.LoyaltyProgram::MAX_LEVELS],
            'loyalty_levels.*' => ['array:spent,percent'],
            'loyalty_levels.*.spent' => ['required', 'numeric', 'min:0.01', 'max:999999999', 'distinct'],
            'loyalty_levels.*.percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'topup_bonus_enabled' => ['nullable', 'boolean'],
            'topup_bonus_levels' => ['nullable', 'array', 'max:'.TopUpBonus::MAX_LEVELS],
            'topup_bonus_levels.*' => ['array:amount,percent'],
            'topup_bonus_levels.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999', 'distinct'],
            'topup_bonus_levels.*.percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'show_qr_code_on_login' => ['nullable', 'boolean'],
            'allow_guest_self_transfer' => ['nullable', 'boolean'],
            'allow_adding_friends' => ['nullable', 'boolean'],
            'allow_balance_transfer_to_friends' => ['nullable', 'boolean'],
            'agent_idle_logout_minutes' => ['sometimes', 'numeric', 'min:0', 'max:1440'],
            'agent_shop_enabled' => ['nullable', 'boolean'],
            'guest_mode_enabled' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> the first matching key wins, so the wildcards go last */
    public function messages(): array
    {
        return [
            'loyalty_levels.max' => __('Уровней скидки — не больше :max.', ['max' => LoyaltyProgram::MAX_LEVELS]),
            'loyalty_levels.*.spent.required' => __('Укажите сумму трат для каждого уровня.'),
            'loyalty_levels.*.spent.distinct' => __('Суммы трат у уровней не должны повторяться.'),
            'loyalty_levels.*.spent.*' => __('Сумма трат — число больше нуля.'),
            'loyalty_levels.*.percent.required' => __('Укажите скидку для каждого уровня.'),
            'loyalty_levels.*.percent.*' => __('Скидка — от 0,01 до 100%.'),
            'topup_bonus_levels.max' => __('Уровней бонуса — не больше :max.', ['max' => TopUpBonus::MAX_LEVELS]),
            'topup_bonus_levels.*.amount.required' => __('Укажите сумму пополнения для каждого уровня.'),
            'topup_bonus_levels.*.amount.distinct' => __('Суммы пополнения у уровней не должны повторяться.'),
            'topup_bonus_levels.*.amount.*' => __('Сумма пополнения — число больше нуля.'),
            'topup_bonus_levels.*.percent.required' => __('Укажите бонус для каждого уровня.'),
            'topup_bonus_levels.*.percent.*' => __('Бонус — от 0,01 до 100%.'),
        ];
    }

    /** Rows of the levels table left empty are not levels. */
    protected function prepareForValidation(): void
    {
        foreach (['loyalty_levels' => 'spent', 'topup_bonus_levels' => 'amount'] as $field => $threshold) {
            $levels = $this->input($field);
            if (is_array($levels)) {
                $this->merge([$field => array_values(array_filter($levels, fn (mixed $level): bool => ! is_array($level) || ($level[$threshold] ?? null) !== null || ($level['percent'] ?? null) !== null))]);
            }
        }
    }
}
