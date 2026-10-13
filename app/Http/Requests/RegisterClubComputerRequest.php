<?php

namespace App\Http\Requests;

use App\Models\ClubZone;
use App\Services\CurrentClub;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterClubComputerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['device_name', 'device_id', 'mac_address'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
        if (is_string($this->input('mac_address'))) {
            $this->merge(['mac_address' => strtoupper($this->input('mac_address'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'mac_address' => ['nullable', 'string', 'regex:/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/'],
            'zone_id' => [
                'nullable',
                'integer',
                Rule::exists((new ClubZone)->getTable(), 'id')
                    ->where('club_id', app(CurrentClub::class)->id()),
            ],
        ];
    }
}
