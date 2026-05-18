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
            'license_key' => ['required', 'string', 'max:64'],
            'device_fingerprint' => ['required', 'string', 'max:128'],
            'current_users' => ['nullable', 'integer', 'min:0'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
