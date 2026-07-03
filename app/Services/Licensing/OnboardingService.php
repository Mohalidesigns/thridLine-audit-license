<?php

namespace App\Services\Licensing;

use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-shot onboarding of a consuming deployment: find-or-create the
 * organization, mint an API client credential pair, and (optionally) issue a
 * license — all in one transaction. Shared by the admin "Onboard Deployment"
 * flow and the license-server:provision-client CLI command so both behave
 * identically.
 */
class OnboardingService
{
    public function __construct(
        private readonly LicenseEngine $engine,
    ) {}

    /**
     * @param  array{
     *   org_name?:string, org_slug?:string, contact_email?:string, country?:string,
     *   industry?:?string, scopes?:array<int,string>, ips?:array<int,string>,
     *   issue_license?:bool, plan?:string, type?:string, duration_days?:?int,
     *   max_users?:?int, max_activations?:?int, actor_type?:string, actor_id?:?string
     * }  $input
     * @return array{organization:Organization, api_client:ApiClient, client_id:string, client_secret:string, license:?License}
     */
    public function onboard(array $input): array
    {
        $slug = $input['org_slug']
            ?? Str::slug((string) ($input['org_name'] ?? 'deployment')) ?: 'deployment';

        $scopes = $input['scopes'] ?? ['license:activate', 'license:validate', 'license:heartbeat', 'license:deactivate'];
        $ips = $input['ips'] ?? [];
        $actorType = $input['actor_type'] ?? 'system';
        $actorId = $input['actor_id'] ?? null;

        return DB::transaction(function () use ($input, $slug, $scopes, $ips, $actorType, $actorId) {
            $org = Organization::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => (string) ($input['org_name'] ?? $slug),
                    'contact_email' => (string) ($input['contact_email'] ?? ''),
                    'industry' => $input['industry'] ?? null,
                    'country' => (string) ($input['country'] ?? 'NG'),
                ],
            );

            // Opaque credential pair (apgrc_ id matches prod + the admin UI's
            // existing client-create flow). Plaintext secret returned once.
            $clientId = 'apgrc_' . Str::lower(Str::random(24));
            $clientSecret = 'sk_' . Str::lower(Str::random(48));

            $client = ApiClient::create([
                'org_id' => $org->id,
                'client_id' => $clientId,
                'client_secret_hash' => Hash::make($clientSecret),
                'allowed_scopes' => $scopes,
                'allowed_ips' => $ips ?: null,
                'is_active' => true,
            ]);

            AuditLog::record('api_client.provisioned', 'api_client', $client->id, [
                'org_id' => $org->id,
                'scopes' => $scopes,
                'ips' => $ips,
            ], $actorType, $actorId);

            $license = null;
            if ($input['issue_license'] ?? false) {
                $plan = (string) ($input['plan'] ?? config('licensing.default_plan', 'professional'));
                $type = (string) ($input['type'] ?? config('licensing.default_type', 'full'));
                $planConfig = config("licensing.plans.{$plan}");
                $durationDays = (int) ($input['duration_days']
                    ?? config("licensing.types.{$type}.default_duration_days", config('licensing.ttl', 365)));

                $license = License::create([
                    'org_id' => $org->id,
                    'license_key' => $this->engine->generateLicenseKey(),
                    'plan' => $plan,
                    'type' => $type,
                    'features' => $planConfig['features'],
                    'max_users' => (int) ($input['max_users'] ?? $planConfig['max_users']),
                    'max_activations' => (int) ($input['max_activations'] ?? $planConfig['max_activations']),
                    'issued_at' => now(),
                    'expires_at' => now()->addDays($durationDays),
                    'status' => 'active',
                    'issued_by' => $actorType === 'admin' ? $actorId : null,
                ]);

                AuditLog::record('license.issued', 'license', $license->id, [
                    'org_id' => $org->id,
                    'plan' => $plan,
                    'type' => $type,
                    'expires_at' => $license->expires_at->toISOString(),
                ], $actorType, $actorId);
            }

            return [
                'organization' => $org,
                'api_client' => $client,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'license' => $license,
            ];
        });
    }

    /**
     * The .env snippet the operator pastes into the consuming app.
     */
    public function envSnippet(array $result, ?string $serverUrl): string
    {
        $org = $result['organization'];
        $block = "# Licensing — provisioned for {$org->slug} on " . now()->toIso8601String() . "\n"
               . 'LICENSE_SERVER_URL=' . ($serverUrl ?: '<your-licensing-server-url>') . "\n"
               . "LICENSE_CLIENT_ID={$result['client_id']}\n"
               . "LICENSE_CLIENT_SECRET={$result['client_secret']}\n";

        if ($result['license']) {
            $block .= "# LICENSE KEY (activate in the app UI): {$result['license']->license_key}\n";
        }

        return $block;
    }
}
