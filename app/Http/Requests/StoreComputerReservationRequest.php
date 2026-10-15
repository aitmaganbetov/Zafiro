<?php

namespace App\Http\Requests;

use App\Services\CurrentClub;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComputerReservationRequest extends FormRequest
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
            'club_computer_id' => ['required', 'integer', Rule::exists('club_computers', 'id')->where('club_id', $clubId)],
            // The customer is one of the club's users: during the booking they may buy time on the PC.
            'user_id' => ['required', 'integer', Rule::exists('club_memberships', 'user_id')->where('club_id', $clubId)],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.required' => __('Выберите клиента из пользователей клуба.'),
            'user_id.exists' => __('Выберите клиента из пользователей клуба.'),
        ];
    }
}
