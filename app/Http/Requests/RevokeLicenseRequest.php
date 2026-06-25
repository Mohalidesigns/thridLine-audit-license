<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevokeLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'license_id' => ['required', 'uuid', 'exists:licenses,id'],
            'reason' => ['required', 'string', 'max:500'],
            // Typed-confirmation guard: the caller must echo back the exact
            // license key. The controller verifies it matches the target.
            'confirm_license_key' => ['required', 'string'],
            // Grace delay in minutes before the revoke takes effect. 0 (or
            // omitted) = immediate. Capped at 7 days.
            'delay_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10080'],
            // Legacy/optional: still accepted; ignored when delay_minutes is set.
            'effective_immediately' => ['sometimes', 'boolean'],
        ];
    }
}
