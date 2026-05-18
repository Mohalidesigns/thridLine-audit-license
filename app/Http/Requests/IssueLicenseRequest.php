<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'org_id' => ['required', 'uuid', 'exists:organizations,id'],
            'plan' => ['required', 'string', 'in:starter,professional,enterprise'],
            'features' => ['required', 'array'],
            'features.*' => ['boolean'],
            'max_users' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_activations' => ['required', 'integer', 'min:1', 'max:100'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
