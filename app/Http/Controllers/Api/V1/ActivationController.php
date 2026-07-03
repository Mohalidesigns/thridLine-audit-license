<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivateLicenseRequest;
use App\Http\Requests\DeactivateLicenseRequest;
use App\Http\Requests\ValidateLicenseRequest;
use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Services\Licensing\LicenseEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivationController extends Controller
{
    public function __construct(
        private readonly LicenseEngine $engine,
    ) {}

    /**
     * Resolve the authenticated API client attached by AuthenticateApiClient middleware.
     */
    private function apiClient(Request $request): ?ApiClient
    {
        $client = $request->input('api_client');
        return $client instanceof ApiClient ? $client : null;
    }

    /**
     * Enforce that the license belongs to the same org as the API client.
     * Returns a JsonResponse to short-circuit the controller, or null when OK.
     */
    private function assertSameTenant(License $license, ?ApiClient $client, string $action): ?JsonResponse
    {
        if (!$client || $license->org_id !== $client->org_id) {
            AuditLog::record('tenant_mismatch.detected', 'license', $license->id, [
                'action' => $action,
                'license_org_id' => $license->org_id,
                'api_client_org_id' => $client?->org_id,
                'api_client_id' => $client?->id,
            ], 'client_app', $client?->id);

            return response()->json([
                'error' => 'tenant_mismatch',
                'message' => 'This license does not belong to your organization.',
            ], 403);
        }

        return null;
    }

    public function activate(ActivateLicenseRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $license = License::where('license_key', $validated['license_key'])->first();

        if (!$license) {
            AuditLog::record('activation.failed', 'license', null, [
                'reason' => 'license_not_found',
                'license_key' => $validated['license_key'],
            ], 'client_app');

            return response()->json([
                'error' => 'license_not_found',
                'message' => 'No license found with the provided key.',
            ], 404);
        }

        if ($mismatch = $this->assertSameTenant($license, $this->apiClient($request), 'activate')) {
            return $mismatch;
        }

        if ($license->isEffectivelyRevoked()) {
            AuditLog::record('activation.failed', 'license', $license->id, [
                'reason' => 'license_revoked',
            ], 'client_app');

            return response()->json([
                'error' => 'license_revoked',
                'message' => 'This license has been revoked.',
            ], 403);
        }

        if (!$license->isActive()) {
            AuditLog::record('activation.failed', 'license', $license->id, [
                'reason' => 'license_not_active',
                'status' => $license->status,
            ], 'client_app');

            return response()->json([
                'error' => 'license_not_active',
                'message' => "License is {$license->status}.",
            ], 403);
        }

        // Check if device already activated
        $existing = LicenseActivation::where('license_id', $license->id)
            ->where('device_fingerprint', $validated['device_fingerprint'])
            ->where('status', 'active')
            ->first();

        if ($existing) {
            // Refresh deployment metadata on re-activation (version upgrades,
            // domain moves) alongside the liveness timestamp.
            $existing->update(array_filter([
                'last_seen_at' => now(),
                'domain' => $validated['domain'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'app_env' => $validated['app_env'] ?? null,
            ], fn ($v) => $v !== null));
            $token = $this->engine->generateToken($license, $validated['device_fingerprint']);

            return response()->json([
                'data' => [
                    'activation_id' => $existing->id,
                    'license_token' => $token,
                    'activated_at' => $existing->activated_at->toISOString(),
                    'entitlements' => $this->entitlements($license),
                    'heartbeat_interval_hours' => config('licensing.heartbeat_interval_hours'),
                    'grace_period_days' => $license->gracePeriodDays(),
                ],
            ]);
        }

        // Enforce max_activations under a row lock so two concurrent activations
        // for different devices can't both pass the count check and overshoot the
        // ceiling (TOCTOU). Both requests serialize on the license row, so the
        // second one sees the first one's insert before re-counting.
        $activation = DB::transaction(function () use ($license, $validated, $request) {
            // Acquire an exclusive lock on the license row for this transaction.
            License::whereKey($license->id)->lockForUpdate()->first();

            $activeCount = $license->activeActivations()->count();
            if ($activeCount >= $license->max_activations) {
                return ['limit_reached' => true, 'current_activations' => $activeCount];
            }

            $meta = array_filter([
                'hostname' => $validated['hostname'] ?? null,
                'domain' => $validated['domain'] ?? null,
                'ip_address' => $validated['ip_address'] ?? $request->ip(),
                'os_info' => $validated['os_info'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'app_env' => $validated['app_env'] ?? null,
            ], fn ($v) => $v !== null);

            // A previously-deactivated row for this (license, device) still holds
            // the unique slot, so reactivate it in place rather than inserting a
            // duplicate. This is the re-activation path after an admin fingerprint
            // reset (or a device that self-deactivated and comes back).
            $prior = LicenseActivation::where('license_id', $license->id)
                ->where('device_fingerprint', $validated['device_fingerprint'])
                ->first();

            if ($prior) {
                $prior->update($meta + [
                    'status' => 'active',
                    'activated_at' => now(),
                    'last_seen_at' => now(),
                    'deactivated_at' => null,
                ]);

                return $prior;
            }

            return LicenseActivation::create($meta + [
                'license_id' => $license->id,
                'device_fingerprint' => $validated['device_fingerprint'],
                'activated_at' => now(),
                'last_seen_at' => now(),
                'status' => 'active',
            ]);
        });

        if (is_array($activation)) {
            AuditLog::record('activation.limit_reached', 'license', $license->id, [
                'current_activations' => $activation['current_activations'],
                'max_activations' => $license->max_activations,
            ], 'client_app');

            return response()->json([
                'error' => 'activation_limit_reached',
                'message' => "Maximum activations ({$license->max_activations}) reached for this license.",
                'current_activations' => $activation['current_activations'],
            ], 409);
        }

        $token = $this->engine->generateToken($license, $validated['device_fingerprint']);

        AuditLog::record('license.activated', 'activation', $activation->id, [
            'license_id' => $license->id,
            'device_fingerprint' => $validated['device_fingerprint'],
            'hostname' => $validated['hostname'] ?? null,
        ], 'client_app', $this->apiClient($request)?->id);

        return response()->json([
            'data' => [
                'activation_id' => $activation->id,
                'license_token' => $token,
                'activated_at' => $activation->activated_at->toISOString(),
                'entitlements' => $this->entitlements($license),
                'heartbeat_interval_hours' => config('licensing.heartbeat_interval_hours'),
                'grace_period_days' => $license->gracePeriodDays(),
            ],
        ]);
    }

    /**
     * Shared entitlements block returned by activate/validate. Includes the
     * license type (full|trial|demo|poc|grace) and a derived is_trial flag so
     * the consumer can surface evaluation banners without re-deriving config.
     */
    private function entitlements(License $license): array
    {
        return [
            'features' => $license->features,
            'max_users' => $license->max_users,
            'plan' => $license->plan,
            'type' => $license->type ?? 'full',
            'is_trial' => $license->isTrialType(),
            'expires_at' => $license->expires_at->toISOString(),
        ];
    }

    public function validate(ValidateLicenseRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $license = License::where('license_key', $validated['license_key'])->first();

        if (!$license) {
            return response()->json([
                'error' => 'license_not_found',
                'message' => 'No license found with the provided key.',
            ], 404);
        }

        if ($mismatch = $this->assertSameTenant($license, $this->apiClient($request), 'validate')) {
            return $mismatch;
        }

        // Check revocation (status flag OR an in-effect revocation row;
        // cancelled and future-scheduled rows are ignored)
        if ($license->isEffectivelyRevoked()) {
            AuditLog::record('validation.failed', 'license', $license->id, [
                'reason' => 'license_revoked',
            ], 'client_app');

            return response()->json([
                'error' => 'license_revoked',
                'message' => 'This license has been revoked.',
            ], 403);
        }

        // Check expiry
        if ($license->isExpired()) {
            AuditLog::record('validation.failed', 'license', $license->id, [
                'reason' => 'license_expired',
                'expired_at' => $license->expires_at->toISOString(),
            ], 'client_app');

            return response()->json([
                'error' => 'license_expired',
                'message' => 'This license has expired.',
            ], 403);
        }

        // Check device fingerprint
        $activation = LicenseActivation::where('license_id', $license->id)
            ->where('device_fingerprint', $validated['device_fingerprint'])
            ->where('status', 'active')
            ->first();

        if (!$activation) {
            AuditLog::record('validation.failed', 'license', $license->id, [
                'reason' => 'device_mismatch',
                'device_fingerprint' => $validated['device_fingerprint'],
            ], 'client_app');

            return response()->json([
                'error' => 'device_mismatch',
                'message' => 'Device fingerprint does not match any active activation.',
            ], 403);
        }

        // Check user limit
        $currentUsers = $validated['current_users'] ?? 0;
        if ($currentUsers > $license->max_users) {
            AuditLog::record('validation.failed', 'license', $license->id, [
                'reason' => 'user_limit_exceeded',
                'current_users' => $currentUsers,
                'max_users' => $license->max_users,
            ], 'client_app');

            return response()->json([
                'error' => 'user_limit_exceeded',
                'message' => "Current users ({$currentUsers}) exceeds maximum ({$license->max_users}).",
            ], 403);
        }

        // Update last seen
        $activation->update(['last_seen_at' => now()]);

        AuditLog::record('validation.success', 'license', $license->id, [
            'device_fingerprint' => $validated['device_fingerprint'],
            'current_users' => $currentUsers,
        ], 'client_app', $this->apiClient($request)?->id);

        return response()->json([
            'data' => [
                'valid' => true,
                'status' => $license->status,
                'license_key' => $license->license_key,
                'entitlements' => $this->entitlements($license),
                'revoked' => false,
                'expires_at' => $license->expires_at->toISOString(),
                'days_remaining' => (int) now()->diffInDays($license->expires_at, false),
                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Deactivate a license activation (client-initiated).
     */
    public function deactivate(DeactivateLicenseRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $license = License::where('license_key', $validated['license_key'])->first();

        if (!$license) {
            return response()->json([
                'error' => 'license_not_found',
                'message' => 'No license found with the provided key.',
            ], 404);
        }

        if ($mismatch = $this->assertSameTenant($license, $this->apiClient($request), 'deactivate')) {
            return $mismatch;
        }

        $activation = LicenseActivation::where('license_id', $license->id)
            ->where('device_fingerprint', $validated['device_fingerprint'])
            ->where('status', 'active')
            ->first();

        if (!$activation) {
            return response()->json([
                'error' => 'activation_not_found',
                'message' => 'No active activation found for this device.',
            ], 404);
        }

        $activation->update([
            'status' => 'deactivated',
            'deactivated_at' => now(),
        ]);

        AuditLog::record('license.deactivated', 'activation', $activation->id, [
            'license_id' => $license->id,
            'device_fingerprint' => $validated['device_fingerprint'],
            'initiated_by' => 'client',
        ], 'client_app', $this->apiClient($request)?->id);

        return response()->json([
            'data' => [
                'deactivated' => true,
                'activation_id' => $activation->id,
                'deactivated_at' => now()->toISOString(),
            ],
        ]);
    }
}
