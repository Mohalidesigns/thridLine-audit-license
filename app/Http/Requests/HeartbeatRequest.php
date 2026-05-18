<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
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
            'active_users' => ['nullable', 'integer', 'min:0'],
            'feature_usage' => ['nullable', 'array'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
