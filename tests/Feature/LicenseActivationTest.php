<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Organization;
use App\Models\RevocationList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LicenseActivationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private License $license;
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

        // Create API client with hashed secret
        $this->plainClientSecret = 'test-client-secret-12345';
        $this->apiClient = ApiClient::create([
            'org_id' => $this->organization->id,
            'client_id' => 'test-client-id',
            'client_secret_hash' => Hash::make($this->plainClientSecret),
            'allowed_scopes' => ['licensing:activate', 'licensing:validate'],
            'is_active' => true,
        ]);

        // Create a valid license
        $this->license = License::create([
            'org_id' => $this->organization->id,
            'license_key' => 'APGRC-TEST-1234-5678-9ABC',
            'plan' => 'professional',
            'features' => ['audit' => true, 'risk' => true, 'compliance' => true],
            'max_users' => 10,
            'max_activations' => 2,
            'issued_at' => now(),
            'expires_at' => now()->addDays(365),
            'status' => 'active',
        ]);
    }

    private function activationHeaders(): array
    {
        return [
            'X-Client-Id' => $this->apiClient->client_id,
            'X-Client-Secret' => $this->plainClientSecret,
        ];
    }

    // Activation Tests
    public function test_successful_activation_of_valid_license(): void
    {
        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-fingerprint-abc123',
            'hostname' => 'test-machine',
            'os_info' => 'Windows 10',
        ], $this->activationHeaders());

        $response->assertStatus(200)
            ->assertJson(['data' => [
                'entitlements' => [
                    'features' => $this->license->features,
                    'max_users' => 10,
                ]
            ]])
            ->assertJsonStructure(['data' => [
                'activation_id',
                'license_token',
                'activated_at',
                'entitlements' => ['features', 'max_users', 'expires_at'],
                'heartbeat_interval_hours',
                'grace_period_days',
            ]]);

        // Verify activation was created
        $this->assertTrue(
            LicenseActivation::where('license_id', $this->license->id)
                ->where('device_fingerprint', 'device-fingerprint-abc123')
                ->exists()
        );
    }

    public function test_activation_fails_for_nonexistent_license_key(): void
    {
        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => 'APGRC-NOPE-9999-9999-9999',
            'device_fingerprint' => 'device-fingerprint-abc123',
        ], $this->activationHeaders());

        $response->assertStatus(404)
            ->assertJson(['error' => 'license_not_found']);
    }

    public function test_activation_fails_for_inactive_license(): void
    {
        $this->license->update(['status' => 'suspended']);

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-fingerprint-abc123',
        ], $this->activationHeaders());

        $response->assertStatus(403)
            ->assertJson(['error' => 'license_not_active']);
    }

    public function test_activation_fails_for_revoked_license(): void
    {
        $this->license->update(['status' => 'revoked']);

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-fingerprint-abc123',
        ], $this->activationHeaders());

        $response->assertStatus(403)
            ->assertJson(['error' => 'license_not_active']);
    }

    public function test_activation_fails_when_max_activations_reached(): void
    {
        // Create max activations
        for ($i = 0; $i < $this->license->max_activations; $i++) {
            LicenseActivation::create([
                'license_id' => $this->license->id,
                'device_fingerprint' => 'device-' . $i,
                'activated_at' => now(),
                'last_seen_at' => now(),
                'status' => 'active',
            ]);
        }

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-new-one',
        ], $this->activationHeaders());

        $response->assertStatus(409)
            ->assertJson(['error' => 'activation_limit_reached'])
            ->assertJson(['current_activations' => $this->license->max_activations]);
    }

    public function test_reactivation_of_same_device_returns_existing_activation(): void
    {
        // First activation
        $firstResponse = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
        ], $this->activationHeaders());

        $firstActivationId = $firstResponse->json('data.activation_id');

        // Reactivate same device
        $secondResponse = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
        ], $this->activationHeaders());

        $secondActivationId = $secondResponse->json('data.activation_id');

        // Should return the same activation ID
        $this->assertEquals($firstActivationId, $secondActivationId);
        $this->assertSame(
            LicenseActivation::where('license_id', $this->license->id)->count(),
            1
        );
    }

    public function test_reactivation_updates_last_seen_at(): void
    {
        // First activation
        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
        ], $this->activationHeaders());

        $activation = LicenseActivation::where('license_id', $this->license->id)->first();
        $firstLastSeenAt = $activation->last_seen_at;

        // Wait a moment and reactivate
        sleep(1);
        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
        ], $this->activationHeaders());

        $activation->refresh();
        $this->assertGreaterThan($firstLastSeenAt->timestamp, $activation->last_seen_at->timestamp);
    }

    // Deactivation Tests
    public function test_successful_deactivation_of_license_activation(): void
    {
        // First activate
        $activation = LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => 'device-abc123',
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/licenses/deactivate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
        ], $this->activationHeaders());

        $response->assertStatus(200)
            ->assertJson(['data' => ['deactivated' => true]]);

        $activation->refresh();
        $this->assertEquals('deactivated', $activation->status);
        $this->assertNotNull($activation->deactivated_at);
    }

    public function test_deactivation_fails_for_nonexistent_license(): void
    {
        $response = $this->postJson('/api/v1/licenses/deactivate', [
            'license_key' => 'APGRC-NOPE-9999-9999-9999',
            'device_fingerprint' => 'device-abc123',
        ], $this->activationHeaders());

        $response->assertStatus(404)
            ->assertJson(['error' => 'license_not_found']);
    }

    public function test_deactivation_fails_for_nonexistent_activation(): void
    {
        $response = $this->postJson('/api/v1/licenses/deactivate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-nonexistent',
        ], $this->activationHeaders());

        $response->assertStatus(404)
            ->assertJson(['error' => 'activation_not_found']);
    }

    // Validation Tests
    public function test_validation_succeeds_for_valid_license_and_device(): void
    {
        // Activate first
        LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => 'device-abc123',
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/licenses/validate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
            'current_users' => 5,
        ], $this->activationHeaders());

        $response->assertStatus(200)
            ->assertJson(['data' => [
                'valid' => true,
                'revoked' => false,
            ]])
            ->assertJsonStructure(['data' => [
                'valid',
                'status',
                'entitlements' => ['features', 'max_users', 'plan'],
                'revoked',
                'expires_at',
                'days_remaining',
                'server_time',
            ]]);
    }

    public function test_validation_fails_for_revoked_license(): void
    {
        // Activate first
        LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => 'device-abc123',
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);

        // Revoke the license
        $this->license->update(['status' => 'revoked']);
        RevocationList::create([
            'license_id' => $this->license->id,
            'reason' => 'test-revocation',
            'revoked_by' => null,
            'revoked_at' => now(),
            'effective_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/licenses/validate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
        ], $this->activationHeaders());

        $response->assertStatus(403)
            ->assertJson(['error' => 'license_revoked']);
    }

    public function test_validation_fails_for_expired_license(): void
    {
        // Activate first
        LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => 'device-abc123',
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);

        // Expire the license
        $this->license->update(['expires_at' => now()->subDay()]);

        $response = $this->postJson('/api/v1/licenses/validate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
        ], $this->activationHeaders());

        $response->assertStatus(403)
            ->assertJson(['error' => 'license_expired']);
    }

    public function test_validation_fails_for_device_mismatch(): void
    {
        // Activate with one device
        LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => 'device-abc123',
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);

        // Try to validate with different device
        $response = $this->postJson('/api/v1/licenses/validate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-different',
        ], $this->activationHeaders());

        $response->assertStatus(403)
            ->assertJson(['error' => 'device_mismatch']);
    }

    public function test_validation_fails_for_user_limit_exceeded(): void
    {
        // Activate first
        LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => 'device-abc123',
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/licenses/validate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
            'current_users' => 15, // Exceeds max_users of 10
        ], $this->activationHeaders());

        $response->assertStatus(403)
            ->assertJson(['error' => 'user_limit_exceeded']);
    }

    public function test_validation_updates_last_seen_at(): void
    {
        // Activate first
        $activation = LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => 'device-abc123',
            'activated_at' => now(),
            'last_seen_at' => now()->subHours(2),
            'status' => 'active',
        ]);

        $oldLastSeenAt = $activation->last_seen_at;

        sleep(1);
        $this->postJson('/api/v1/licenses/validate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
        ], $this->activationHeaders());

        $activation->refresh();
        $this->assertGreaterThan($oldLastSeenAt->timestamp, $activation->last_seen_at->timestamp);
    }

    // Authentication Tests
    public function test_missing_api_client_credentials_returns_401(): void
    {
        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
        ]); // No headers

        $response->assertStatus(401)
            ->assertJson(['error' => 'missing_credentials']);
    }

    public function test_invalid_api_client_credentials_returns_401(): void
    {
        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => 'device-abc123',
        ], [
            'X-Client-Id' => 'invalid-client-id',
            'X-Client-Secret' => 'invalid-secret',
        ]);

        $response->assertStatus(401)
            ->assertJson(['error' => 'invalid_credentials']);
    }

    public function test_activation_requires_valid_device_fingerprint(): void
    {
        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $this->license->license_key,
            'device_fingerprint' => '', // Empty
        ], $this->activationHeaders());

        $response->assertStatus(422); // Validation error
    }
}
