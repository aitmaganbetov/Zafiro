<?php

namespace App\Http\Requests;

use App\Models\ClubComputer;
use App\Services\AgentTariffPurchaseService;
use App\Services\CurrentClub;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseAgentTariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'computer_id' => [
                'required',
                'integer',
                Rule::exists((new ClubComputer)->getTable(), 'id')
                    ->where('club_id', app(CurrentClub::class)->id()),
            ],
            // main | bonus; agents before 0.0.57 send none.
            'balance' => ['nullable', Rule::in(AgentTariffPurchaseService::BALANCES)],
        ];
    }
}
