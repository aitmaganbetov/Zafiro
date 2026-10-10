<?php

namespace App\Http\Requests;

use App\Models\ActivityLog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterActivityLogsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'event' => ['nullable', Rule::in(array_keys(ActivityLog::EVENT_LABELS))],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }
}
