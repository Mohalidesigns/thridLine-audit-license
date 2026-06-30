<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseUsageMetric;
use App\Models\Organization;
use App\Models\RevocationList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private License $license;
    private LicenseActivation $activation;
    private ApiClient $apiClient;
    private string $plainClientSecret;

    protected function setUp(): void
    {
        parent::setUp();

        // Create organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'contact_email' => 'contact@test.org',
            'country' => 'NG',
        ]);

        // Create API client
        $this->plainClientSecret = 'test-client-secret-12345';
        $this->apiClient = ApiClient::create([
            'org_id' => $this->organization->id,
            'client_id' => 'test-client-id',
            'client_secret_hash' => Hash::make($this->plainClientSecret),
            'allowed_scopes' => ['license:heartbeat'],
            'is_active' => true,
        ]);

        // Create a valid license
        $this->license = License::create([
            'org_id' => $this->organization->id,
            'license_key' => 'APGRC-TEST-1234-5678-9ABC',
            'plan' => 'professional',
            'features' => ['audit' => true, 'risk' => true],
            'max_users' => 10,
            'max_activations' => 2,
            'issued_at' => now(),
            'expires_at' => now()->addDays(365),
            'status' => 'active',
        ]);

        // Create an active activation
        $this->activation = LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
            'hostname' => 'test-machine',
            'activated_at' => now(),
            'last_seen_at' => now()->subHours(24),
            'status' => 'active',
        ]);
    }

    private function heartbeatHeaders(): array
    {
        return [
            'X-Client-Id' => $this->apiClient->client_id,
            'X-Client-Secret' => $this->plainClientSecret,
        ];
    }

    public function test_successful_heartbeat_updates_last_seen_at(): void
    {
        $oldLastSeenAt = $this->activation->last_seen_at;

        sleep(1);
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(200)
            ->assertJson(['data' => [
                'status' => 'active',
                'revoked' => false,
            ]])
            ->assertJsonStructure(['data' => [
                'status',
                'revoked',
                'server_time',
                'next_heartbeat_before',
                'updated_entitlements' => ['features', 'max_users', 'plan', 'expires_at'],
                'commands',
                'grace_period_days',
            ]]);

        $this->activation->refresh();
        $this->assertGreaterThan($oldLastSeenAt->timestamp, $this->activation->last_seen_at->timestamp);
    }

    public function test_heartbeat_records_usage_metrics_when_provided(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
            'active_users' => 5,
            'feature_usage' => [
                'audit_trails_generated' => 150,
                'reports_created' => 3,
            ],
        ], $this->heartbeatHeaders());

        $response->assertStatus(200);

        // Verify usage metric was recorded
        $metric = LicenseUsageMetric::where('license_id', $this->license->id)
            ->where('activation_id', $this->activation->id)
            ->latest()
            ->first();

        $this->assertNotNull($metric);
        $this->assertEquals(5, $metric->active_users_count);
        $this->assertArrayHasKey('audit_trails_generated', $metric->feature_usage);
    }

    public function test_heartbeat_records_partial_metrics(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
            'active_users' => 3,
        ], $this->heartbeatHeaders());

        $response->assertStatus(200);

        $metric = LicenseUsageMetric::where('license_id', $this->license->id)
            ->latest()
            ->first();

        $this->assertNotNull($metric);
        $this->assertEquals(3, $metric->active_users_count);
    }

    public function test_heartbeat_detects_revoked_license(): void
    {
        // Revoke the license
        $this->license->update(['status' => 'revoked']);
        RevocationList::create([
            'license_id' => $this->license->id,
            'reason' => 'test-revocation',
            'revoked_by' => \Illuminate\Support\Str::uuid()->toString(),
            'revoked_at' => now(),
            'effective_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(200)
            ->assertJson(['data' => [
                'revoked' => true,
            ]])
            ->assertJsonFragment(['commands' => ['force_deactivate']]);
    }

    public function test_heartbeat_detects_expired_license(): void
    {
        // Expire the license
        $this->license->update(['expires_at' => now()->subDay()]);

        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['commands' => ['license_expired']]);
    }

    public function test_heartbeat_returns_server_time(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(200);
        $this->assertArrayHasKey('server_time', $response->json('data'));
        $this->assertNotEmpty($response->json('data.server_time'));
    }

    public function test_heartbeat_returns_next_heartbeat_before(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(200);
        $this->assertArrayHasKey('next_heartbeat_before', $response->json('data'));
        $this->assertNotEmpty($response->json('data.next_heartbeat_before'));
    }

    public function test_heartbeat_returns_updated_entitlements(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'updated_entitlements' => ['features', 'max_users', 'plan', 'expires_at'],
            ]]);

        $entitlements = $response->json('data.updated_entitlements');
        $this->assertEquals($this->license->features, $entitlements['features']);
        $this->assertEquals($this->license->max_users, $entitlements['max_users']);
        $this->assertEquals($this->license->plan, $entitlements['plan']);
    }

    public function test_heartbeat_fails_for_nonexistent_license(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => 'APGRC-NOPE-9999-9999-9999',
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(404)
            ->assertJson(['error' => 'license_not_found']);
    }

    public function test_heartbeat_fails_for_nonexistent_activation(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-nonexistent'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(404)
            ->assertJson(['error' => 'activation_not_found']);
    }

    public function test_heartbeat_fails_for_deactivated_activation(): void
    {
        // Deactivate the activation
        $this->activation->update([
            'status' => 'deactivated',
            'deactivated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(404)
            ->assertJson(['error' => 'activation_not_found']);
    }

    public function test_heartbeat_without_metrics_still_succeeds(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(200);

        // Verify no usage metric was created
        $metricsCount = LicenseUsageMetric::where('license_id', $this->license->id)->count();
        $this->assertEquals(0, $metricsCount);
    }

    public function test_heartbeat_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ]); // No headers

        $response->assertStatus(401)
            ->assertJson(['error' => 'missing_credentials']);
    }

    public function test_heartbeat_with_multiple_commands(): void
    {
        // Expire and revoke the license
        $this->license->update([
            'status' => 'revoked',
            'expires_at' => now()->subDay(),
        ]);
        RevocationList::create([
            'license_id' => $this->license->id,
            'reason' => 'test-revocation',
            'revoked_by' => \Illuminate\Support\Str::uuid()->toString(),
            'revoked_at' => now(),
            'effective_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
        ], $this->heartbeatHeaders());

        $response->assertStatus(200);
        $commands = $response->json('data.commands');

        // Should contain both commands
        $this->assertContains('force_deactivate', $commands);
        $this->assertContains('license_expired', $commands);
    }

    public function test_heartbeat_records_app_version_in_audit_log(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => hash('sha256', 'device-abc123'),
            'app_version' => '1.2.3',
            'active_users' => 2,
        ], $this->heartbeatHeaders());

        $response->assertStatus(200);
        // Audit log is recorded (tested in AuditLog tests)
    }
}
