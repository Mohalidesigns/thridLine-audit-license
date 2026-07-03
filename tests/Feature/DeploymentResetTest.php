<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeploymentResetTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $viewerUser;
    private License $license;
    private LicenseActivation $activation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $org = Organization::create([
            'name' => 'Reset Test Org',
            'slug' => 'reset-test-org',
            'contact_email' => 'reset@test.org',
            'country' => 'NG',
        ]);

        $this->adminUser = User::factory()->create(['is_active' => true]);
        $this->adminUser->assignRole('super-admin');

        $this->viewerUser = User::factory()->create(['is_active' => true]);
        $this->viewerUser->assignRole('viewer');

        $this->license = License::create([
            'org_id' => $org->id,
            'license_key' => 'APGRC-RSET-1234-5678-9ABC',
            'plan' => 'professional',
            'type' => 'full',
            'features' => ['audit' => true],
            'max_users' => 10,
            'max_activations' => 1,
            'issued_at' => now(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
        ]);

        $this->activation = LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => hash('sha256', 'old-hardware'),
            'hostname' => 'old-box',
            'activated_at' => now()->subMonth(),
            'last_seen_at' => now()->subDay(),
            'status' => 'active',
        ]);
    }

    private function actingAsWithToken(User $user): self
    {
        Sanctum::actingAs($user, $user->getAllPermissions()->pluck('name')->all() ?: ['none'], 'sanctum');

        return $this;
    }

    public function test_admin_reset_releases_slot_and_frees_reactivation(): void
    {
        $response = $this->actingAsWithToken($this->adminUser)
            ->postJson("/api/v1/deployments/{$this->activation->id}/deactivate", [
                'reason' => 'customer replaced server hardware',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'deactivated')
            ->assertJsonPath('data.freed_slots', 1);

        $this->assertDatabaseHas('license_activations', [
            'id' => $this->activation->id,
            'status' => 'deactivated',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'activation.reset',
            'resource_id' => $this->activation->id,
        ]);

        // The freed slot admits a NEW fingerprint on the same license
        // (max_activations = 1, so this only works because the slot freed).
        $newActivation = LicenseActivation::create([
            'license_id' => $this->license->id,
            'device_fingerprint' => hash('sha256', 'new-hardware'),
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);
        $this->assertSame(1, $this->license->activeActivations()->count());
        $this->assertSame($newActivation->id, $this->license->activeActivations()->first()->id);
    }

    public function test_reset_is_guarded_against_double_release(): void
    {
        $this->activation->update(['status' => 'deactivated', 'deactivated_at' => now()]);

        $this->actingAsWithToken($this->adminUser)
            ->postJson("/api/v1/deployments/{$this->activation->id}/deactivate")
            ->assertStatus(409)
            ->assertJsonPath('error', 'activation_not_active');
    }

    public function test_viewer_cannot_reset(): void
    {
        $this->actingAsWithToken($this->viewerUser)
            ->postJson("/api/v1/deployments/{$this->activation->id}/deactivate")
            ->assertStatus(403);

        $this->assertDatabaseHas('license_activations', [
            'id' => $this->activation->id,
            'status' => 'active',
        ]);
    }
}
