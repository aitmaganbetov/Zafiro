<?php

namespace App\Http\Requests;

use App\Models\ClubZone;
use App\Services\CurrentClub;
use App\Support\MacAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClubComputerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('mac_address'))) {
            $mac = trim($this->input('mac_address'));
            $this->merge(['mac_address' => $mac === '' ? null : (MacAddress::normalize($mac) ?? $mac)]);
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $clubId = app(CurrentClub::class)->id();

        return [
            'number' => [
                'required',
                'integer',
                'min:1',
                'max:4294967295',
                Rule::unique('club_computers', 'number')
                    ->where('club_id', $clubId)
                    ->where('club_zone_id', $this->input('club_zone_id'))
                    ->ignore($this->route('clubComputer')),
            ],
            'display_name' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('club_computers', 'display_name')
                    ->where('club_id', $clubId)
                    ->ignore($this->route('clubComputer')),
            ],
            // Only staff move a MAC to another card: agents never change a card's MAC.
            'mac_address' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/',
                Rule::unique('club_computers', 'mac_address')
                    ->where('club_id', $clubId)
                    ->ignore($this->route('clubComputer')),
            ],
            'club_zone_id' => [
                'required',
                'integer',
                Rule::exists((new ClubZone)->getTable(), 'id')
                    ->where('club_id', $clubId),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'number.unique' => __('Номер уже используется в этой зоне.'),
            'display_name.unique' => __('Это имя уже используется в клубе.'),
            'mac_address.regex' => __('Введите MAC-адрес в формате AA:BB:CC:DD:EE:FF.'),
            'mac_address.unique' => __('Этот MAC-адрес уже привязан к другому ПК клуба.'),
        ];
    }
}
