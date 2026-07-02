<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\Organization;
use App\Services\Licensing\LicenseEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provision a consuming application (organization + API client) so it can talk
 * to the LicensingServer API. Prints the plaintext client_secret ONCE and writes
 * a .env snippet for the operator to paste into the consuming app.
 *
 * Idempotent on organization slug — re-running with the same slug reuses the
 * organization but always issues a fresh client/secret pair.
 *
 * Usage:
 *   php artisan license-server:provision-client \
 *       --org-slug=thirdline-acme \
 *       --org-name="ACME Bank Internal Audit" \
 *       --contact-email=audit@acme.ng \
 *       --country=NG \
 *       --scopes=license:activate,license:validate,license:heartbeat,license:deactivate \
 *       --ips=203.0.113.10 \
 *       --server-url=https://license.atherislimited.com \
 *       --issue-license --plan=professional --type=trial
 *
 * With --issue-license the command also creates a license for the organization
 * (plan features/limits from config, duration from the type's default window),
 * making this a one-shot onboarding for a new deployment.
 */
class ProvisionClientCommand extends Command
{
    protected $signature = 'license-server:provision-client
        {--org-slug=         : Organization slug (required, lowercase-hyphenated)}
        {--org-name=         : Organization display name (required for new orgs)}
        {--contact-email=    : Organization contact email (required for new orgs)}
        {--country=NG        : ISO country code (default: NG)}
        {--industry=         : Industry label (optional)}
        {--scopes=license:activate,license:validate,license:heartbeat,license:deactivate : Comma-separated scopes}
        {--ips=              : Comma-separated allowed source IPs (optional)}
        {--server-url=       : LicensingServer URL to include in the .env snippet (optional)}
        {--issue-license     : Also issue a license for the organization (one-shot onboarding)}
        {--plan=professional : License plan when issuing (starter|professional|enterprise)}
        {--type=full         : License type when issuing (full|trial|demo|poc|grace)}
        {--duration-days=    : License duration; defaults to the type\'s default window}
        {--max-users=        : Override the plan\'s default max users}
        {--max-activations=  : Override the plan\'s default max activations}';

    protected $description = 'Provision an organization + API client (and optionally a license) for a consuming application and emit credentials once.';

    public function handle(): int
    {
        $slug = (string) $this->option('org-slug');
        if ($slug === '') {
            $this->error('--org-slug is required.');
            return self::FAILURE;
        }

        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('scopes')))));
        if (empty($scopes)) {
            $this->error('At least one scope is required.');
            return self::FAILURE;
        }

        $ips = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('ips')))));

        // Validate licensing options up front so a typo doesn't half-provision.
        $plan = (string) $this->option('plan');
        $type = (string) $this->option('type');
        if ($this->option('issue-license')) {
            if (!config("licensing.plans.{$plan}")) {
                $this->error("Unknown plan '{$plan}'. Valid: " . implode(', ', array_keys(config('licensing.plans', []))));
                return self::FAILURE;
            }
            if (!config("licensing.types.{$type}")) {
                $this->error("Unknown type '{$type}'. Valid: " . implode(', ', array_keys(config('licensing.types', []))));
                return self::FAILURE;
            }
        }

        return DB::transaction(function () use ($slug, $scopes, $ips, $plan, $type) {
            $org = Organization::where('slug', $slug)->first();

            if (!$org) {
                $name  = (string) $this->option('org-name');
                $email = (string) $this->option('contact-email');

                if ($name === '' || $email === '') {
                    $this->error('Organization not found by slug. To create it, provide both --org-name and --contact-email.');
                    return self::FAILURE;
                }

                $org = Organization::create([
                    'name'          => $name,
                    'slug'          => $slug,
                    'contact_email' => $email,
                    'industry'      => $this->option('industry') ?: null,
                    'country'       => (string) ($this->option('country') ?: 'NG'),
                ]);

                $this->info("Created organization {$org->id} ({$org->slug}).");
            } else {
                $this->info("Reusing existing organization {$org->id} ({$org->slug}).");
            }

            // Always mint a fresh credential pair so a re-run rotates secrets.
            $clientId     = 'tlc_' . Str::lower(Str::random(24));
            $clientSecret = 'sk_' . Str::lower(Str::random(48));

            $client = ApiClient::create([
                'org_id'             => $org->id,
                'client_id'          => $clientId,
                'client_secret_hash' => Hash::make($clientSecret),
                'allowed_scopes'     => $scopes,
                'allowed_ips'        => $ips ?: null,
                'is_active'          => true,
            ]);

            AuditLog::record('api_client.provisioned', 'api_client', $client->id, [
                'org_id'  => $org->id,
                'scopes'  => $scopes,
                'ips'     => $ips,
                'via'     => 'cli',
            ], 'system');

            // Optionally issue a license in the same transaction (one-shot onboarding).
            $license = null;
            if ($this->option('issue-license')) {
                $planConfig   = config("licensing.plans.{$plan}");
                $durationDays = (int) ($this->option('duration-days')
                    ?: config("licensing.types.{$type}.default_duration_days", config('licensing.ttl', 365)));

                $license = License::create([
                    'org_id'          => $org->id,
                    'license_key'     => app(LicenseEngine::class)->generateLicenseKey(),
                    'plan'            => $plan,
                    'type'            => $type,
                    'features'        => $planConfig['features'],
                    'max_users'       => (int) ($this->option('max-users') ?: $planConfig['max_users']),
                    'max_activations' => (int) ($this->option('max-activations') ?: $planConfig['max_activations']),
                    'issued_at'       => now(),
                    'expires_at'      => now()->addDays($durationDays),
                    'status'          => 'active',
                ]);

                AuditLog::record('license.issued', 'license', $license->id, [
                    'org_id' => $org->id,
                    'plan'   => $plan,
                    'type'   => $type,
                    'expires_at' => $license->expires_at->toISOString(),
                    'via'    => 'cli',
                ], 'system');

                $this->info("Issued {$type} {$plan} license {$license->license_key} (expires {$license->expires_at->toDateString()}).");
            }

            // Build a .env snippet for the operator to paste into the consuming app.
            $serverUrl = $this->option('server-url') ?: '<your-licensing-server-url>';
            $envBlock  = "# Licensing — provisioned for {$org->slug} on " . now()->toIso8601String() . "\n"
                       . "LICENSE_SERVER_URL={$serverUrl}\n"
                       . "LICENSE_CLIENT_ID={$clientId}\n"
                       . "LICENSE_CLIENT_SECRET={$clientSecret}\n";
            if ($license) {
                // Not an env var — the key is entered in Settings > License > Activate.
                $envBlock .= "# LICENSE KEY (activate in the app UI): {$license->license_key}\n";
            }

            $dir = storage_path('app/provisioned-clients');
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $path = $dir . DIRECTORY_SEPARATOR . $slug . '.env';
            file_put_contents($path, $envBlock);
            @chmod($path, 0600);

            $this->newLine();
            $this->line('────────────────────────────────────────────────────────');
            $this->info('API client provisioned. Credentials shown ONCE — store them now.');
            $this->line('────────────────────────────────────────────────────────');
            $this->line($envBlock);
            $this->line('Snippet also written to: ' . $path);
            $this->line('Paste the LICENSE_* lines into the consuming app\'s .env, then restart it.');
            $this->newLine();

            return self::SUCCESS;
        });
    }
}
