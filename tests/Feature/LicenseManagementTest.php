<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Organization;
use App\Models\RevocationList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicenseManagementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Organization $otherOrganization;
    private User $adminUser;
    private License $license;

    protected function setUp(): void
    {
        parent::setUp();

        // Create organizations
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'contact_email' => 'contact@test.org',
            'country' => 'NG',
        ]);

        $this->otherOrganization = Organization::create([
            'name' => 'Other Organization',
            'slug' => 'other-org',
            'contact_email' => 'other@org.test',
            'country' => 'NG',
        ]);

        // Create admin user
        $this->adminUser = User::factory()->create([
            'email' => 'admin@test.com',
            'name' => 'Admin User',
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
            'issued_by' => $this->adminUser->id,
        ]);
    }

    private function authenticateAs(User $user): self
    {
        return $this->actingAs($user, 'sanctum');
    }

    // License Creation Tests
    public function test_license_creation_store(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses', [
                'org_id' => $this->organization->id,
                'plan' => 'enterprise',
                'features' => [
                    'audit' => true,
                    'risk' => true,
                    'compliance' => true,
                ],
                'max_users' => 50,
                'max_activations' => 5,
                'duration_days' => 365,
                'notes' => 'Test license',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => [
                'id',
                'license_key',
                'token',
                'plan',
                'features',
                'issued_at',
                'expires_at',
                'status',
                'file' => ['content', 'filename'],
            ]])
            ->assertJson(['data' => [
                'plan' => 'enterprise',
                'status' => 'active',
            ]]);

        // Verify license was created in database
        $this->assertDatabaseHas('licenses', [
            'org_id' => $this->organization->id,
            'plan' => 'enterprise',
            'max_users' => 50,
        ]);
    }

    public function test_license_creation_generates_unique_keys(): void
    {
        $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses', [
                'org_id' => $this->organization->id,
                'plan' => 'starter',
                'features' => ['audit' => true],
                'max_users' => 5,
                'max_activations' => 1,
                'duration_days' => 365,
            ]);

        $response2 = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses', [
                'org_id' => $this->organization->id,
                'plan' => 'starter',
                'features' => ['audit' => true],
                'max_users' => 5,
                'max_activations' => 1,
                'duration_days' => 365,
            ]);

        $key1 = License::where('org_id', $this->organization->id)
            ->where('plan', 'starter')
            ->first()
            ->license_key;

        $key2 = $response2->json('data.license_key');

        $this->assertNotEquals($key1, $key2);
    }

    // License Listing Tests
    public function test_license_listing_index(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->getJson('/api/v1/licenses');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    ['*' => [
                        'id',
                        'license_key',
                        'plan',
                        'status',
                        'organization',
                    ]],
                ],
                'meta',
                'links',
            ]);
    }

    public function test_license_listing_with_status_filter(): void
    {
        // Create an active license
        License::create([
            'org_id' => $this->organization->id,
            'license_key' => 'APGRC-STAT-2345-6789-0DEF',
            'plan' => 'starter',
            'features' => ['audit' => true],
            'max_users' => 5,
            'max_activations' => 1,
            'issued_at' => now(),
            'expires_at' => now()->addDays(365),
            'status' => 'active',
        ]);

        // Create a suspended license
        License::create([
            'org_id' => $this->organization->id,
            'license_key' => 'APGRC-SUSP-3456-7890-1EFG',
            'plan' => 'professional',
            'features' => ['audit' => true, 'risk' => true],
            'max_users' => 10,
            'max_activations' => 2,
            'issued_at' => now(),
            'expires_at' => now()->addDays(365),
            'status' => 'suspended',
        ]);

        $response = $this->authenticateAs($this->adminUser)
            ->getJson('/api/v1/licenses?status=active');

        $licenses = $response->json('data');
        $this->assertTrue(
            collect($licenses)->every(fn ($license) => $license['status'] === 'active')
        );
    }

    public function test_license_listing_with_org_id_filter(): void
    {
        License::create([
            'org_id' => $this->otherOrganization->id,
            'license_key' => 'APGRC-OTH1-2345-6789-0ABC',
            'plan' => 'starter',
            'features' => ['audit' => true],
            'max_users' => 5,
            'max_activations' => 1,
            'issued_at' => now(),
            'expires_at' => now()->addDays(365),
            'status' => 'active',
        ]);

        $response = $this->authenticateAs($this->adminUser)
            ->getJson('/api/v1/licenses?org_id=' . $this->organization->id);

        $licenses = $response->json('data');
        $this->assertTrue(
            collect($licenses)->every(fn ($license) => $license['organization']['id'] === $this->organization->id)
        );
    }

    public function test_license_listing_with_plan_filter(): void
    {
        License::create([
            'org_id' => $this->organization->id,
            'license_key' => 'APGRC-PLAN-2345-6789-0ABC',
            'plan' => 'starter',
            'features' => ['audit' => true],
            'max_users' => 5,
            'max_activations' => 1,
            'issued_at' => now(),
            'expires_at' => now()->addDays(365),
            'status' => 'active',
        ]);

        $response = $this->authenticateAs($this->adminUser)
            ->getJson('/api/v1/licenses?plan=professional');

        $licenses = $response->json('data');
        $this->assertTrue(
            collect($licenses)->every(fn ($license) => $license['plan'] === 'professional')
        );
    }

    public function test_license_listing_with_search_by_license_key(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->getJson('/api/v1/licenses?search=APGRC-TEST-1234');

        $licenses = $response->json('data');
        $this->assertTrue(
            collect($licenses)->every(fn ($license) => str_contains($license['license_key'], 'APGRC-TEST-1234'))
        );
    }

    public function test_license_listing_with_search_by_org_name(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->getJson('/api/v1/licenses?search=Test Organization');

        $licenses = $response->json('data');
        $this->assertTrue(
            collect($licenses)->every(fn ($license) => $license['organization']['name'] === 'Test Organization')
        );
    }

    // License Show Tests
    public function test_license_show_with_details(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->getJson('/api/v1/licenses/' . $this->license->id);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'id',
                'license_key',
                'plan',
                'features',
                'max_users',
                'max_activations',
                'issued_at',
                'expires_at',
                'status',
                'organization',
                'activations',
            ]]);
    }

    public function test_license_show_includes_revocations(): void
    {
        RevocationList::create([
            'license_id' => $this->license->id,
            'reason' => 'test-revocation',
            'revoked_by' => $this->adminUser->id,
            'revoked_at' => now(),
        ]);

        $response = $this->authenticateAs($this->adminUser)
            ->getJson('/api/v1/licenses/' . $this->license->id);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'revocations',
            ]]);
    }

    // License Update Tests
    public function test_license_update(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->putJson('/api/v1/licenses/' . $this->license->id, [
                'plan' => 'enterprise',
                'max_users' => 50,
                'notes' => 'Updated license',
            ]);

        $response->assertStatus(200)
            ->assertJson(['data' => [
                'plan' => 'enterprise',
                'max_users' => 50,
            ]]);

        $this->license->refresh();
        $this->assertEquals('enterprise', $this->license->plan);
        $this->assertEquals(50, $this->license->max_users);
    }

    public function test_license_update_can_suspend(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->putJson('/api/v1/licenses/' . $this->license->id, [
                'status' => 'suspended',
            ]);

        $response->assertStatus(200)
            ->assertJson(['data' => [
                'status' => 'suspended',
            ]]);

        $this->license->refresh();
        $this->assertEquals('suspended', $this->license->status);
    }

    // License Revoke Tests
    public function test_license_revoke(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses/revoke', [
                'license_id' => $this->license->id,
                'reason' => 'Customer request',
                'confirm_license_key' => $this->license->license_key,
                'effective_immediately' => true,
            ]);

        $response->assertStatus(200)
            ->assertJson(['data' => [
                'license_id' => $this->license->id,
                'status' => 'revoked',
            ]])
            ->assertJsonStructure(['data' => [
                'license_id',
                'status',
                'revoked_at',
                'affected_activations',
            ]]);

        $this->license->refresh();
        $this->assertEquals('revoked', $this->license->status);

        // Verify revocation record was created
        $this->assertDatabaseHas('revocation_list', [
            'license_id' => $this->license->id,
            'reason' => 'Customer request',
        ]);
    }

    public function test_license_revoke_deactivates_active_activations(): void
    {
        // Create active activations
        $activation1 = LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => 'device-1',
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);

        $activation2 = LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => 'device-2',
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);

        $response = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses/revoke', [
                'license_id' => $this->license->id,
                'reason' => 'Test revoke',
                'confirm_license_key' => $this->license->license_key,
                'effective_immediately' => true,
            ]);

        $response->assertJson(['data' => [
                'affected_activations' => 2,
            ]]);

        $activation1->refresh();
        $activation2->refresh();

        $this->assertEquals('deactivated', $activation1->status);
        $this->assertEquals('deactivated', $activation2->status);
    }

    public function test_license_revoke_of_already_revoked_license_returns_409(): void
    {
        $this->license->update(['status' => 'revoked']);

        $response = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses/revoke', [
                'license_id' => $this->license->id,
                'reason' => 'Test',
                'confirm_license_key' => $this->license->license_key,
                'effective_immediately' => true,
            ]);

        $response->assertStatus(409)
            ->assertJson(['error' => 'already_revoked']);
    }

    // License Renewal Tests
    public function test_license_renewal(): void
    {
        $oldExpiresAt = $this->license->expires_at;

        $response = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses/' . $this->license->id . '/renew', [
                'duration_days' => 365,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'id',
                'expires_at',
                'status',
            ]]);

        $this->license->refresh();
        $this->assertGreaterThan($oldExpiresAt->timestamp, $this->license->expires_at->timestamp);
        $this->assertEquals('active', $this->license->status);
    }

    public function test_license_renewal_of_revoked_license_fails(): void
    {
        $this->license->update(['status' => 'revoked']);

        $response = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses/' . $this->license->id . '/renew', [
                'duration_days' => 365,
            ]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'license_revoked']);
    }

    public function test_license_renewal_of_expired_license(): void
    {
        $this->license->update(['expires_at' => now()->subDay()]);

        $response = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses/' . $this->license->id . '/renew', [
                'duration_days' => 365,
            ]);

        $response->assertStatus(200);

        $this->license->refresh();
        // Should extend from current time since expired
        $this->assertGreaterThan(now()->timestamp, $this->license->expires_at->timestamp);
    }

    // License Transfer Tests
    public function test_license_transfer_to_new_organization(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses/' . $this->license->id . '/transfer', [
                'new_org_id' => $this->otherOrganization->id,
                'reason' => 'Customer migration',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'license',
                'new_license_key',
                'file',
                'deactivated_activations',
            ]]);

        $this->license->refresh();
        $this->assertEquals($this->otherOrganization->id, $this->license->org_id);
    }

    public function test_license_transfer_deactivates_active_activations(): void
    {
        LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => 'device-abc',
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);

        $response = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses/' . $this->license->id . '/transfer', [
                'new_org_id' => $this->otherOrganization->id,
                'reason' => 'Test transfer',
            ]);

        $response->assertJson(['data' => [
                'deactivated_activations' => 1,
            ]]);
    }

    public function test_license_transfer_generates_new_license_key(): void
    {
        $oldKey = $this->license->license_key;

        $response = $this->authenticateAs($this->adminUser)
            ->postJson('/api/v1/licenses/' . $this->license->id . '/transfer', [
                'new_org_id' => $this->otherOrganization->id,
                'reason' => 'Test transfer',
            ]);

        $newKey = $response->json('data.new_license_key');
        $this->assertNotEquals($oldKey, $newKey);

        $this->license->refresh();
        $this->assertEquals($newKey, $this->license->license_key);
    }

    // License File Tests
    public function test_license_file_download(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->get('/api/v1/licenses/' . $this->license->id . '/download-file');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_license_file_download_blocked_for_revoked_license(): void
    {
        $this->license->update(['status' => 'revoked']);

        $response = $this->authenticateAs($this->adminUser)
            ->get('/api/v1/licenses/' . $this->license->id . '/download-file');

        $response->assertStatus(403)
            ->assertJson(['error' => 'license_revoked']);
    }

    public function test_license_file_generate_json(): void
    {
        $response = $this->authenticateAs($this->adminUser)
            ->getJson('/api/v1/licenses/' . $this->license->id . '/generate-file');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'content',
                'filename',
            ]]);

        // Verify content is valid base64
        $content = $response->json('data.content');
        $this->assertNotEmpty(base64_decode($content, true));
    }

    public function test_license_file_generate_blocked_for_revoked_license(): void
    {
        $this->license->update(['status' => 'revoked']);

        $response = $this->authenticateAs($this->adminUser)
            ->getJson('/api/v1/licenses/' . $this->license->id . '/generate-file');

        $response->assertStatus(403)
            ->assertJson(['error' => 'license_revoked']);
    }

    public function test_license_management_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/licenses');

        $response->assertStatus(401); // Unauthenticated
    }
}
