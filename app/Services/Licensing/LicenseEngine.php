<?php

namespace App\Services\Licensing;

use App\Models\License;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;

class LicenseEngine
{
    private string $privateKey;
    private string $publicKey;
    private string $algorithm;

    public function __construct()
    {
        $privateKeyPath = config('licensing.keys.private');
        $publicKeyPath = config('licensing.keys.public');

        $this->privateKey = file_exists($privateKeyPath) ? file_get_contents($privateKeyPath) : '';
        $this->publicKey = file_exists($publicKeyPath) ? file_get_contents($publicKeyPath) : '';
        $this->algorithm = config('licensing.algorithm');
    }

    public function generateToken(License $license, ?string $deviceFingerprint = null): string
    {
        $license->loadMissing('organization');

        $payload = [
            'iss'  => config('licensing.issuer'),
            'sub'  => $license->org_id,
            'jti'  => $license->id,
            'iat'  => now()->timestamp,
            'nbf'  => now()->timestamp,
            'exp'  => $license->expires_at->timestamp,
            'lk'   => $license->license_key,
            'plan' => $license->plan,
            'ltyp' => $license->type ?? 'full',
            'feat' => $license->features,
            'mu'   => $license->max_users,
            'ma'   => $license->max_activations,
            'org'  => [
                'id'   => $license->organization->id,
                'name' => $license->organization->name,
                'slug' => $license->organization->slug,
            ],
            'ver'  => '1.0',
            'chk'  => $this->generateIntegrityHash($license),
        ];

        if ($deviceFingerprint) {
            $payload['dvc'] = $deviceFingerprint;
        }

        return JWT::encode($payload, $this->privateKey, $this->algorithm);
    }

    public function validateToken(string $token): object
    {
        return JWT::decode($token, new Key($this->publicKey, $this->algorithm));
    }

    public function generateLicenseKey(): string
    {
        do {
            $key = 'APGRC-' . implode('-', [
                strtoupper(Str::random(4)),
                strtoupper(Str::random(4)),
                strtoupper(Str::random(4)),
                strtoupper(Str::random(4)),
            ]);
        } while (License::where('license_key', $key)->exists());

        return $key;
    }

    /**
     * Generate a downloadable license file payload.
     * The .lic file is a base64-encoded JSON envelope containing the signed JWT,
     * license key, metadata, and a file-level signature — functionally identical
     * to online key activation but usable for offline import.
     */
    public function generateLicenseFile(License $license, ?string $deviceFingerprint = null): array
    {
        $license->loadMissing('organization');
        $token = $this->generateToken($license, $deviceFingerprint);

        $envelope = [
            'format'      => 'auditpro-grc-license',
            'version'     => '1.0',
            'license_key' => $license->license_key,
            'token'       => $token,
            'license'     => [
                'id'              => $license->id,
                'plan'            => $license->plan,
                'type'            => $license->type ?? 'full',
                'features'        => $license->features,
                'max_users'       => $license->max_users,
                'max_activations' => $license->max_activations,
                'issued_at'       => $license->issued_at->toISOString(),
                'expires_at'      => $license->expires_at->toISOString(),
            ],
            'organization' => [
                'id'   => $license->organization->id,
                'name' => $license->organization->name,
                'slug' => $license->organization->slug,
            ],
            'issued_by'   => config('licensing.issuer'),
            'generated_at' => now()->toISOString(),
        ];

        if ($deviceFingerprint) {
            $envelope['device_fingerprint'] = $deviceFingerprint;
        }

        // File-level HMAC so the client app can verify the envelope was not tampered with
        $envelope['signature'] = hash_hmac(
            'sha256',
            $envelope['token'] . '|' . $envelope['license_key'] . '|' . $envelope['license']['expires_at'],
            config('app.key'),
        );

        $encoded = base64_encode(json_encode($envelope, JSON_UNESCAPED_SLASHES));

        return [
            'envelope'  => $envelope,
            'encoded'   => $encoded,
            'filename'  => $this->licenseFilename($license),
        ];
    }

    /**
     * Consistent filename for a license file download.
     */
    public function licenseFilename(License $license): string
    {
        $license->loadMissing('organization');
        $slug = $license->organization->slug ?? 'license';
        return "APGRC-{$slug}-{$license->plan}.lic";
    }

    private function generateIntegrityHash(License $license): string
    {
        $data = $license->id . $license->org_id . $license->plan
              . json_encode($license->features) . $license->expires_at->timestamp;

        return hash_hmac('sha256', $data, config('app.key'));
    }
}
