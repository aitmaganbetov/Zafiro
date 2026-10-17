<?php

namespace App\Http\Requests;

use App\Models\ClubComputer;
use App\Support\MacAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HeartbeatClubComputerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('device_name'))) {
            $this->merge(['device_name' => trim($this->input('device_name'))]);
        }
        if (is_string($this->input('mac_address'))) {
            $this->merge(['mac_address' => MacAddress::normalize($this->input('mac_address')) ?? $this->input('mac_address')]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'required', 'string', Rule::in(ClubComputer::AGENT_STATES)],
            'session_id' => ['sometimes', 'nullable', 'string', 'max:255', 'prohibited_unless:state,in_session'],
            'session_ends_at' => ['sometimes', 'nullable', 'date', 'prohibited_unless:state,in_session'],
            'agent_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mac_address' => ['sometimes', 'nullable', 'string', 'regex:/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/'],
            'local_ip' => ['sometimes', 'nullable', 'ip'],
            'cpu_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cpu_cores' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1024'],
            'ram_total_mb' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:16777216'],
            'gpu_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
