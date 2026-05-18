<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure(['status', 'service', 'version', 'timestamp']);
    }

    public function test_health_endpoint_returns_service_name(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson(['service' => 'thirdline-licensing-server']);
    }

    public function test_health_endpoint_requires_no_auth(): void
    {
        // No headers, no auth token - should still work
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
    }

    public function test_health_endpoint_includes_version(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $this->assertArrayHasKey('version', $response->json());
        $this->assertNotEmpty($response->json('version'));
    }

    public function test_health_endpoint_includes_timestamp(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $this->assertArrayHasKey('timestamp', $response->json());
    }
}
