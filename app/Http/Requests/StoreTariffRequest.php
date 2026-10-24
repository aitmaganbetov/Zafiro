<?php

namespace App\Http\Requests;

use App\Models\ClubZone;
use App\Models\Tariff;
use App\Services\CurrentClub;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique((new Tariff)->getTable(), 'name')->where('club_id', app(CurrentClub::class)->id())],
            'description' => ['nullable', 'string', 'max:500'],
            'duration_value' => ['required', 'integer', 'min:1', 'max:10080'],
            'duration_unit' => ['required', Rule::in(['minutes', 'hours', 'days'])],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'payment_method' => ['required', Rule::in(['main_balance_only', 'main_or_bonus'])],
            'discount_enabled' => ['required', 'boolean'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'club_name' => ['required', 'string', 'max:100'],
            'zone_ids' => ['nullable', 'array', 'max:100'],
            'zone_ids.*' => ['required', 'integer', 'distinct', Rule::in(ClubZone::query()->whereHas('computers')->pluck('id')->all())],
            'usage_limit_enabled' => ['required', 'boolean'],
            'usage_limit_value' => ['nullable', 'required_if:usage_limit_enabled,1', 'integer', 'min:1', 'max:10080'],
            'usage_limit_unit' => ['nullable', 'required_if:usage_limit_enabled,1', Rule::in(['minutes', 'hours', 'days'])],
            'quantity_limit_enabled' => ['required', 'boolean'],
            'quantity_limit' => ['nullable', 'required_if:quantity_limit_enabled,1', 'integer', 'min:1', 'max:1000000'],
            'validity_periods' => ['required', 'array', 'min:1', 'max:50'],
            'validity_periods.*.start_day' => ['required', Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
            'validity_periods.*.start_time' => ['required', 'date_format:H:i'],
            'validity_periods.*.end_day' => ['required', Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
            'validity_periods.*.end_time' => ['required', 'date_format:H:i'],
            'sale_periods' => ['nullable', 'array', 'max:50'],
            'sale_periods.*.start_day' => ['required', Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
            'sale_periods.*.start_time' => ['required', 'date_format:H:i'],
            'sale_periods.*.end_day' => ['required', Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
            'sale_periods.*.end_time' => ['required', 'date_format:H:i'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
