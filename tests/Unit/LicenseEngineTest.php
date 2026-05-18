<?php

namespace Tests\Unit;

use App\Models\License;
use App\Models\Organization;
use App\Services\Licensing\LicenseEngine;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use Tests\TestCase as BaseTestCase;

class LicenseEngineTest extends BaseTestCase
{
    use RefreshDatabase;

    private LicenseEngine $engine;
    private Organization $organization;
    private License $license;
    private string $privateKeyPath;
    private string $publicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate test RSA keys
        $this->generateTestKeys();

        // Set config to use test keys
        config(['licensing.keys.private' => $this->privateKeyPath]);
        config(['licensing.keys.public' => $this->publicKeyPath]);
        config(['licensing.issuer' => 'test-issuer']);
        config(['licensing.algorithm' => 'RS256']);

        $this->engine = new LicenseEngine();

        // Create test organization and license
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'contact_email' => 'contact@test.org',
            'country' => 'NG',
        ]);

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
    }

    protected function tearDown(): void
    {
        // Clean up test keys
        if (file_exists($this->privateKeyPath)) {
            unlink($this->privateKeyPath);
        }
        if (file_exists($this->publicKeyPath)) {
            unlink($this->publicKeyPath);
        }
        parent::tearDown();
    }

    private function generateTestKeys(): void
    {
        // Use storage_path as the config does
        $keysDir = storage_path('keys');
        if (!is_dir($keysDir)) {
            mkdir($keysDir, 0755, true);
        }

        $this->privateKeyPath = $keysDir . '/test_private.pem';
        $this->publicKeyPath = $keysDir . '/test_public.pem';

        // Generate RSA key pair
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // Generate private key
        $resource = openssl_pkey_new($config);
        openssl_pkey_export($resource, $privateKey);
        file_put_contents($this->privateKeyPath, $privateKey);

        // Extract public key
        $details = openssl_pkey_get_details($resource);
        $publicKey = $details['key'];
        file_put_contents($this->publicKeyPath, $publicKey);
    }

    // License Key Generation Tests
    public function test_license_key_generation_follows_apgrc_format(): void
    {
        $key = $this->engine->generateLicenseKey();

        // Format: APGRC-XXXX-XXXX-XXXX-XXXX
        $this->assertMatchesRegularExpression(
            '/^APGRC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/',
            $key
        );
    }

    public function test_license_key_generation_produces_unique_keys(): void
    {
        $keys = [];
        for ($i = 0; $i < 10; $i++) {
            $key = $this->engine->generateLicenseKey();
            $this->assertNotContains($key, $keys, 'Generated duplicate license key');
            $keys[] = $key;
        }

        $this->assertCount(10, array_unique($keys));
    }

    public function test_license_key_generation_does_not_generate_duplicate_of_existing(): void
    {
        // Create multiple licenses to fill the key space slightly
        for ($i = 0; $i < 5; $i++) {
            License::create([
                'org_id' => $this->organization->id,
                'license_key' => $this->engine->generateLicenseKey(),
                'plan' => 'starter',
                'features' => ['audit' => true],
                'max_users' => 5,
                'max_activations' => 1,
                'issued_at' => now(),
                'expires_at' => now()->addDays(365),
                'status' => 'active',
            ]);
        }

        $key = $this->engine->generateLicenseKey();

        // Verify the key doesn't already exist
        $this->assertFalse(License::where('license_key', $key)->exists());
    }

    // Token Generation and Validation Tests
    public function test_token_generation_and_validation_roundtrip(): void
    {
        $token = $this->engine->generateToken($this->license);

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Decode and validate the token
        $decoded = $this->engine->validateToken($token);

        $this->assertNotNull($decoded);
        $this->assertEquals($this->license->id, $decoded->jti);
        $this->assertEquals($this->license->license_key, $decoded->lk);
        $this->assertEquals($this->license->plan, $decoded->plan);
    }

    public function test_generated_token_contains_license_data(): void
    {
        $token = $this->engine->generateToken($this->license);
        $decoded = $this->engine->validateToken($token);

        // Verify payload contains correct data
        $this->assertEquals($this->license->org_id, $decoded->sub);
        $this->assertEquals($this->license->id, $decoded->jti);
        $this->assertEquals($this->license->license_key, $decoded->lk);
        $this->assertEquals($this->license->plan, $decoded->plan);
        $this->assertEquals($this->license->features, (array)$decoded->feat);
        $this->assertEquals($this->license->max_users, $decoded->mu);
        $this->assertEquals($this->license->max_activations, $decoded->ma);
    }

    public function test_generated_token_contains_organization_data(): void
    {
        $token = $this->engine->generateToken($this->license);
        $decoded = $this->engine->validateToken($token);

        // Verify organization data
        $this->assertEquals($this->organization->id, $decoded->org->id);
        $this->assertEquals($this->organization->name, $decoded->org->name);
        $this->assertEquals($this->organization->slug, $decoded->org->slug);
    }

    public function test_generated_token_includes_timestamps(): void
    {
        $token = $this->engine->generateToken($this->license);
        $decoded = $this->engine->validateToken($token);

        // Verify timestamp fields
        $this->assertIsInt($decoded->iat); // issued at
        $this->assertIsInt($decoded->nbf); // not before
        $this->assertIsInt($decoded->exp); // expiration
        $this->assertGreaterThanOrEqual($decoded->iat, $decoded->nbf);
        $this->assertGreaterThan($decoded->exp, $decoded->iat); // exp should be in the future
    }

    public function test_token_expiration_matches_license_expiry(): void
    {
        $token = $this->engine->generateToken($this->license);
        $decoded = $this->engine->validateToken($token);

        // Token expiration should match license expiration
        $this->assertEquals(
            $this->license->expires_at->timestamp,
            $decoded->exp
        );
    }

    public function test_token_includes_integrity_hash(): void
    {
        $token = $this->engine->generateToken($this->license);
        $decoded = $this->engine->validateToken($token);

        // Verify checksum is present
        $this->assertNotEmpty($decoded->chk);
        $this->assertIsString($decoded->chk);
    }

    public function test_token_signature_verification(): void
    {
        $token = $this->engine->generateToken($this->license);

        // Token should be validatable
        $this->assertDoesNotThrow(function () use ($token) {
            $this->engine->validateToken($token);
        });
    }

    public function test_invalid_token_fails_validation(): void
    {
        $this->expectException(\Exception::class);

        // Create invalid token
        $invalidToken = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.invalid.invalid';
        $this->engine->validateToken($invalidToken);
    }

    public function test_tampered_token_fails_validation(): void
    {
        $token = $this->engine->generateToken($this->license);

        // Tamper with the token
        $parts = explode('.', $token);
        $parts[2] = 'invalidsignature'; // Replace signature
        $tamperedToken = implode('.', $parts);

        $this->expectException(\Exception::class);
        $this->engine->validateToken($tamperedToken);
    }

    // License File Generation Tests
    public function test_license_file_generation_produces_valid_envelope(): void
    {
        $fileData = $this->engine->generateLicenseFile($this->license);

        $this->assertIsArray($fileData);
        $this->assertArrayHasKey('envelope', $fileData);
        $this->assertArrayHasKey('encoded', $fileData);
        $this->assertArrayHasKey('filename', $fileData);

        $envelope = $fileData['envelope'];
        $this->assertEquals('auditpro-grc-license', $envelope['format']);
        $this->assertEquals('1.0', $envelope['version']);
        $this->assertEquals($this->license->license_key, $envelope['license_key']);
    }

    public function test_license_file_envelope_contains_jwt_token(): void
    {
        $fileData = $this->engine->generateLicenseFile($this->license);
        $envelope = $fileData['envelope'];

        $this->assertArrayHasKey('token', $envelope);
        $this->assertIsString($envelope['token']);

        // Token should be validatable
        $decoded = $this->engine->validateToken($envelope['token']);
        $this->assertEquals($this->license->license_key, $decoded->lk);
    }

    public function test_license_file_envelope_contains_license_metadata(): void
    {
        $fileData = $this->engine->generateLicenseFile($this->license);
        $envelope = $fileData['envelope'];

        $this->assertArrayHasKey('license', $envelope);
        $license = $envelope['license'];

        $this->assertEquals($this->license->id, $license['id']);
        $this->assertEquals($this->license->plan, $license['plan']);
        $this->assertEquals($this->license->features, $license['features']);
        $this->assertEquals($this->license->max_users, $license['max_users']);
        $this->assertEquals($this->license->max_activations, $license['max_activations']);
    }

    public function test_license_file_envelope_contains_organization_metadata(): void
    {
        $fileData = $this->engine->generateLicenseFile($this->license);
        $envelope = $fileData['envelope'];

        $this->assertArrayHasKey('organization', $envelope);
        $org = $envelope['organization'];

        $this->assertEquals($this->organization->id, $org['id']);
        $this->assertEquals($this->organization->name, $org['name']);
        $this->assertEquals($this->organization->slug, $org['slug']);
    }

    public function test_license_file_envelope_contains_signature(): void
    {
        $fileData = $this->engine->generateLicenseFile($this->license);
        $envelope = $fileData['envelope'];

        $this->assertArrayHasKey('signature', $envelope);
        $this->assertIsString($envelope['signature']);
        $this->assertNotEmpty($envelope['signature']);

        // Signature should be a valid SHA256 hash (64 hex characters)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $envelope['signature']);
    }

    public function test_license_file_signature_includes_token_key_and_expiry(): void
    {
        $fileData = $this->engine->generateLicenseFile($this->license);
        $envelope = $fileData['envelope'];

        $expectedSignature = hash_hmac(
            'sha256',
            $envelope['token'] . '|' . $envelope['license_key'] . '|' . $envelope['license']['expires_at'],
            config('app.key'),
        );

        $this->assertEquals($expectedSignature, $envelope['signature']);
    }

    public function test_license_file_encoded_is_valid_base64(): void
    {
        $fileData = $this->engine->generateLicenseFile($this->license);

        $encoded = $fileData['encoded'];
        $decoded = base64_decode($encoded, true);

        $this->assertNotFalse($decoded);
        $this->assertIsString($decoded);

        // Decoded should be valid JSON
        $json = json_decode($decoded, true);
        $this->assertIsArray($json);
    }

    public function test_license_file_encoded_contains_full_envelope(): void
    {
        $fileData = $this->engine->generateLicenseFile($this->license);

        $encoded = $fileData['encoded'];
        $decoded = base64_decode($encoded, true);
        $envelope = json_decode($decoded, true);

        $this->assertEquals('auditpro-grc-license', $envelope['format']);
        $this->assertEquals($this->license->license_key, $envelope['license_key']);
        $this->assertArrayHasKey('token', $envelope);
        $this->assertArrayHasKey('signature', $envelope);
    }

    public function test_license_file_has_consistent_filename(): void
    {
        $filename = $this->engine->licenseFilename($this->license);

        $this->assertStringStartsWith('APGRC-', $filename);
        $this->assertStringEndsWith('.lic', $filename);
        $this->assertStringContainsString($this->organization->slug, $filename);
        $this->assertStringContainsString($this->license->plan, $filename);
    }

    public function test_license_file_filename_format(): void
    {
        $filename = $this->engine->licenseFilename($this->license);

        // Format: APGRC-{slug}-{plan}.lic
        $expected = "APGRC-{$this->organization->slug}-{$this->license->plan}.lic";
        $this->assertEquals($expected, $filename);
    }

    public function test_license_file_generation_includes_issued_dates(): void
    {
        $fileData = $this->engine->generateLicenseFile($this->license);
        $envelope = $fileData['envelope'];
        $license = $envelope['license'];

        $this->assertArrayHasKey('issued_at', $license);
        $this->assertArrayHasKey('expires_at', $license);
        $this->assertNotEmpty($license['issued_at']);
        $this->assertNotEmpty($license['expires_at']);
    }

    public function test_license_file_generation_includes_generated_timestamp(): void
    {
        $fileData = $this->engine->generateLicenseFile($this->license);
        $envelope = $fileData['envelope'];

        $this->assertArrayHasKey('generated_at', $envelope);
        $this->assertNotEmpty($envelope['generated_at']);
    }

    public function test_license_file_multiple_generations_produce_different_timestamps(): void
    {
        $fileData1 = $this->engine->generateLicenseFile($this->license);

        sleep(1);

        $fileData2 = $this->engine->generateLicenseFile($this->license);

        $timestamp1 = $fileData1['envelope']['generated_at'];
        $timestamp2 = $fileData2['envelope']['generated_at'];

        $this->assertNotEquals($timestamp1, $timestamp2);
    }

    // Helper method for assertion
    private function assertDoesNotThrow(callable $callback): void
    {
        try {
            $callback();
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('Expected no exception, but got: ' . $e->getMessage());
        }
    }
}
