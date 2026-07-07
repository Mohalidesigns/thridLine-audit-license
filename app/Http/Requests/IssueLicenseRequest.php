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
        $featureKeys = array_keys(config('licensing.available_features', []));

        return [
            'org_id' => ['required', 'uuid', 'exists:organizations,id'],
            'plan' => ['required', 'string', 'in:starter,professional,enterprise'],
            'type' => ['sometimes', 'string', 'in:' . $types],
            // Only recognised module keys may be sent (rejects typos/unknowns).
            'features' => ['required', 'array', function ($attr, $value, $fail) use ($featureKeys) {
                $unknown = array_diff(array_keys((array) $value), $featureKeys);
                if ($unknown) {
                    $fail('Unknown feature(s): ' . implode(', ', $unknown));
                }
            }],
            'features.*' => ['boolean'],
            'max_users' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_activations' => ['required', 'integer', 'min:1', 'max:100'],
            // Optional: when omitted, the controller derives expiry from the
            // type's default_duration_days (e.g. trial=14, demo=7, poc=30).
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Normalise features to the full catalog: every known module present with an
     * explicit boolean (ticked => true, everything else => false). The stored
     * license and its JWT `feat` claim then carry all modules explicitly, so the
     * consumer enforces exactly what was selected — no ambiguity from absent keys.
     */
    public function normalizedFeatures(): array
    {
        $posted = (array) $this->input('features', []);
        $out = [];
        foreach (array_keys(config('licensing.available_features', [])) as $key) {
            // Laravel's `boolean` rule accepts "1"/"true"/1 as well as real bools,
            // so coerce the same way rather than a strict === true (which would drop
            // a truthy string from a non-JSON API client to false).
            $out[$key] = filter_var($posted[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }
}
