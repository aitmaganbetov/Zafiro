<?php

namespace App\Http\Requests;

use App\Models\ClubComputer;
use App\Services\CurrentClub;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ControlClubComputersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'computer_ids' => ['required', 'array', 'min:1', 'max:200'],
            'computer_ids.*' => ['required', 'integer', 'distinct', Rule::exists((new ClubComputer)->getTable(), 'id')->where('club_id', app(CurrentClub::class)->id())],
            'action' => ['required', Rule::in(['power_on', 'restart', 'power_off', 'reserve'])],
        ];
    }
}
