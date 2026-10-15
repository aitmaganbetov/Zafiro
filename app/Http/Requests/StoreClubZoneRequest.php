<?php

namespace App\Http\Requests;

use App\Models\Tariff;
use App\Services\CurrentClub;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClubZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:180'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
            // Empty: no per-minute time in this zone, only tariffs.
            'price_per_hour' => ['nullable', 'numeric', 'min:0.01', 'max:1000000', 'decimal:0,2'],
            'icon' => ['required', Rule::in(['crown', 'desktop', 'gamepad', 'mug-hot', 'couch', 'dice', 'door-open', 'bong', 'smoking'])],
            'theme' => ['required', Rule::in(['green', 'neutral', 'yellow', 'purple', 'blue'])],
            'status' => ['required', Rule::in(['available', 'busy', 'service'])],
            'is_featured' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'tariff_ids' => ['sometimes', 'array', 'max:100'],
            'tariff_ids.*' => ['required', 'integer', 'distinct', Rule::exists((new Tariff)->getTable(), 'id')->where('club_id', app(CurrentClub::class)->id())],
        ];
    }
}
