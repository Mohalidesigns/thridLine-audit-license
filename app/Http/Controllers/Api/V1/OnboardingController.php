<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Licensing\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Universal deployment onboarding: create the organization + API client +
 * (optional) license in one call, returning a credential bundle shown once.
 * Wraps OnboardingService (shared with the provision-client CLI command).
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboarder,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $planKeys = implode(',', array_keys(config('licensing.plans', [])));
        $typeKeys = implode(',', array_keys(config('licensing.types', [])));

        $validated = $request->validate([
            'org_name' => ['required', 'string', 'max:255'],
            'org_slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            'contact_email' => ['required', 'email', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'industry' => ['nullable', 'string', 'max:255'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'in:license:activate,license:validate,license:heartbeat,license:deactivate'],
            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => ['ip'],
            'issue_license' => ['sometimes', 'boolean'],
            'plan' => ['required_if:issue_license,true', 'string', 'in:' . $planKeys],
            'type' => ['nullable', 'string', 'in:' . $typeKeys],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_activations' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'server_url' => ['nullable', 'url'],
        ]);

        $result = $this->onboarder->onboard([
            'org_name' => $validated['org_name'],
            'org_slug' => $validated['org_slug'] ?? Str::slug($validated['org_name']),
            'contact_email' => $validated['contact_email'],
            'country' => $validated['country'] ?? 'NG',
            'industry' => $validated['industry'] ?? null,
            'scopes' => $validated['scopes'] ?? null,
            'ips' => $validated['allowed_ips'] ?? [],
            'issue_license' => (bool) ($validated['issue_license'] ?? false),
            'plan' => $validated['plan'] ?? null,
            'type' => $validated['type'] ?? 'full',
            'duration_days' => $validated['duration_days'] ?? null,
            'max_users' => $validated['max_users'] ?? null,
            'max_activations' => $validated['max_activations'] ?? null,
            'actor_type' => 'admin',
            'actor_id' => $request->user()?->id,
        ]);

        $org = $result['organization'];
        $license = $result['license'];

        return response()->json([
            'data' => [
                'organization' => [
                    'id' => $org->id,
                    'name' => $org->name,
                    'slug' => $org->slug,
                ],
                'client_id' => $result['client_id'],
                'client_secret' => $result['client_secret'], // shown ONCE
                'scopes' => $result['api_client']->allowed_scopes,
                'allowed_ips' => $result['api_client']->allowed_ips,
                'license' => $license ? [
                    'license_key' => $license->license_key,
                    'plan' => $license->plan,
                    'type' => $license->type,
                    'max_users' => $license->max_users,
                    'max_activations' => $license->max_activations,
                    'expires_at' => $license->expires_at->toISOString(),
                ] : null,
                'env_snippet' => $this->onboarder->envSnippet($result, $validated['server_url'] ?? config('app.url')),
                'message' => 'Store the client secret now — it will not be shown again.',
            ],
        ], 201);
    }
}
