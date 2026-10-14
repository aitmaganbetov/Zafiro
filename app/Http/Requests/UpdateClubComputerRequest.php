<?php

namespace App\Http\Requests;

use App\Models\ClubZone;
use App\Services\CurrentClub;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClubComputerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
        ];
    }
}
