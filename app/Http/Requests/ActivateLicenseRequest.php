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
            'license_key' => ['required', 'string', 'regex:/^APGRC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/'],
            // sha256 hex (64 chars), with an optional "sha256:" prefix as emitted
            // by the ThirdLine consumer's DeviceFingerprint::generate().
            'device_fingerprint' => ['required', 'string', 'regex:/^(sha256:)?[a-f0-9]{64}$/i'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'os_info' => ['nullable', 'string', 'max:255'],
            // Deployment registry metadata (see admin Deployments view).
            'domain' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'app_env' => ['nullable', 'string', 'max:20'],
        ];
    }
}
