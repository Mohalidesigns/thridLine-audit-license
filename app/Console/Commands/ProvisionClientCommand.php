<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Licensing\OnboardingService;
use Illuminate\Console\Command;

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

    public function __construct(
        private readonly OnboardingService $onboarder,
    ) {
        parent::__construct();
    }

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

        // A new org needs name + email; an existing one (by slug) is reused.
        $existing = Organization::where('slug', $slug)->first();
        $name = (string) $this->option('org-name');
        $email = (string) $this->option('contact-email');
        if (!$existing && ($name === '' || $email === '')) {
            $this->error('Organization not found by slug. To create it, provide both --org-name and --contact-email.');
            return self::FAILURE;
        }

        $result = $this->onboarder->onboard([
            'org_slug'        => $slug,
            'org_name'        => $name ?: $slug,
            'contact_email'   => $email,
            'industry'        => $this->option('industry') ?: null,
            'country'         => (string) ($this->option('country') ?: 'NG'),
            'scopes'          => $scopes,
            'ips'             => $ips,
            'issue_license'   => (bool) $this->option('issue-license'),
            'plan'            => $plan,
            'type'            => $type,
            'duration_days'   => $this->option('duration-days') ? (int) $this->option('duration-days') : null,
            'max_users'       => $this->option('max-users') ? (int) $this->option('max-users') : null,
            'max_activations' => $this->option('max-activations') ? (int) $this->option('max-activations') : null,
            'actor_type'      => 'system',
        ]);

        $this->info(($existing ? 'Reused' : 'Created') . " organization {$result['organization']->id} ({$result['organization']->slug}).");
        if ($result['license']) {
            $lic = $result['license'];
            $this->info("Issued {$lic->type} {$lic->plan} license {$lic->license_key} (expires {$lic->expires_at->toDateString()}).");
        }

        $envBlock = $this->onboarder->envSnippet($result, $this->option('server-url') ?: null);

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
    }
}
