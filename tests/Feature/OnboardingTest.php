<?php
namespace Tests\Feature;
use App\Models\{ApiClient, License, Organization, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class OnboardingTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }
    private function admin(): User { $u=User::factory()->create(['is_active'=>true]); $u->assignRole('super-admin'); Sanctum::actingAs($u,$u->getAllPermissions()->pluck('name')->all(),'sanctum'); return $u; }
    public function test_onboarding_creates_org_client_and_license(): void {
        $this->admin();
        $r = $this->postJson('/api/v1/onboarding', [
            'org_name'=>'ACME Audit','contact_email'=>'a@acme.ng','issue_license'=>true,'plan'=>'enterprise','type'=>'trial','allowed_ips'=>['203.0.113.10'],
        ]);
        $r->assertStatus(201)
          ->assertJsonPath('data.organization.slug','acme-audit')
          ->assertJsonPath('data.license.plan','enterprise')
          ->assertJsonPath('data.license.type','trial')
          ->assertJsonStructure(['data'=>['client_id','client_secret','env_snippet','license'=>['license_key']]]);
        $this->assertStringStartsWith('apgrc_', $r->json('data.client_id'));
        $this->assertDatabaseHas('organizations',['slug'=>'acme-audit']);
        $this->assertSame(1, ApiClient::count());
        $this->assertSame(1, License::count());
    }
    public function test_onboarding_without_license(): void {
        $this->admin();
        $this->postJson('/api/v1/onboarding',['org_name'=>'No License Co','contact_email'=>'x@y.z'])
            ->assertStatus(201)->assertJsonPath('data.license', null);
        $this->assertSame(0, License::count());
    }
    public function test_viewer_forbidden(): void {
        $u=User::factory()->create(['is_active'=>true]); $u->assignRole('viewer');
        Sanctum::actingAs($u,$u->getAllPermissions()->pluck('name')->all()?:['none'],'sanctum');
        $this->postJson('/api/v1/onboarding',['org_name'=>'x','contact_email'=>'x@y.z'])->assertStatus(403);
    }
}
