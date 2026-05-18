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
            'effective_immediately' => ['required', 'boolean'],
        ];
    }
}
