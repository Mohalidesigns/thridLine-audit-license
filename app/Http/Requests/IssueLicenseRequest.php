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
        $types = implode(',', array_keys(config('licensing.types', ['full' => []])));

        return [
            'org_id' => ['required', 'uuid', 'exists:organizations,id'],
            'plan' => ['required', 'string', 'in:starter,professional,enterprise'],
            'type' => ['sometimes', 'string', 'in:' . $types],
            'features' => ['required', 'array'],
            'features.*' => ['boolean'],
            'max_users' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_activations' => ['required', 'integer', 'min:1', 'max:100'],
            // Optional: when omitted, the controller derives expiry from the
            // type's default_duration_days (e.g. trial=14, demo=7, poc=30).
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
