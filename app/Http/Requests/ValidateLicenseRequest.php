<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'license_key' => ['required', 'string', 'regex:/^APGRC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/'],
            'device_fingerprint' => ['required', 'string', 'regex:/^(sha256:)?[a-f0-9]{64}$/i'],
            'current_users' => ['nullable', 'integer', 'min:0'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
