<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActivateLicenseRequest extends FormRequest
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
            'hostname' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'os_info' => ['nullable', 'string', 'max:255'],
        ];
    }
}
