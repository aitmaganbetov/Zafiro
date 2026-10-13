<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveClubComputerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'device_name' => is_string($this->input('device_name')) ? trim($this->input('device_name')) : $this->input('device_name'),
            'device_id' => is_string($this->input('device_id')) ? trim($this->input('device_id')) : $this->input('device_id'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
