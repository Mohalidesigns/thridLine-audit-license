<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\Organization;
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
 *       --server-url=https://license.thirdline-grc.com
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
        {--server-url=       : LicensingServer URL to include in the .env snippet (optional)}';

    protected $description = 'Provision an organization + API client for a consuming application and emit credentials once.';

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

        return DB::transaction(function () use ($slug, $scopes, $ips) {
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

            // Build a .env snippet for the operator to paste into the consuming app.
            $serverUrl = $this->option('server-url') ?: '<your-licensing-server-url>';
            $envBlock  = "# Licensing — provisioned for {$org->slug} on " . now()->toIso8601String() . "\n"
                       . "LICENSE_SERVER_URL={$serverUrl}\n"
                       . "LICENSE_CLIENT_ID={$clientId}\n"
                       . "LICENSE_CLIENT_SECRET={$clientSecret}\n";

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
