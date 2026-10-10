<?php

namespace App\Http\Requests;

use App\Models\ClubZone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClubZoneLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $clubMap = $this->route('clubMap');
        $isCouch = $clubMap instanceof ClubZone && $clubMap->icon === 'couch';

        return [
            'position_x' => ['required', 'integer', 'min:0', 'max:5000'],
            'position_y' => ['required', 'integer', 'min:0', 'max:5000'],
            'width' => ['required', 'integer', 'min:'.($isCouch ? 80 : 180), 'max:1600'],
            'height' => ['required', 'integer', 'min:'.($isCouch ? 60 : 130), 'max:1200'],
            'rotation' => ['sometimes', 'integer', Rule::in([0, 90])],
        ];
    }
}
