<?php

namespace App\Http\Requests;

use App\Models\Tariff;
use App\Services\CurrentClub;
use Illuminate\Validation\Rule;

/** The same fields as a new tariff; the name may stay its own. */
class UpdateTariffRequest extends StoreTariffRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'max:100', Rule::unique((new Tariff)->getTable(), 'name')->where('club_id', app(CurrentClub::class)->id())->ignore($this->route('tariff'))],
        ];
    }
}
