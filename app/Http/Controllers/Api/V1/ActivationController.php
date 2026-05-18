<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivateLicenseRequest;
use App\Http\Requests\ValidateLicenseRequest;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\RevocationList;
use App\Services\Licensing\LicenseEngine;
use Illuminate\Http\JsonResponse;

class ActivationController extends Controller
{
    public function __construct(
        private readonly LicenseEngine $engine,
    ) {}

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
            $existing->update(['last_seen_at' => now()]);
            $token = $this->engine->generateToken($license);

            return response()->json([
                'data' => [
                    'activation_id' => $existing->id,
                    'license_token' => $token,
                    'activated_at' => $existing->activated_at->toISOString(),
                    'entitlements' => [
                        'features' => $license->features,
                        'max_users' => $license->max_users,
                        'expires_at' => $license->expires_at->toISOString(),
                    ],
                    'heartbeat_interval_hours' => config('licensing.heartbeat_interval_hours'),
                    'grace_period_days' => config('licensing.grace_period_days'),
                ],
            ]);
        }

        // Check max activations
        $activeCount = $license->activeActivations()->count();
        if ($activeCount >= $license->max_activations) {
            AuditLog::record('activation.limit_reached', 'license', $license->id, [
                'current_activations' => $activeCount,
                'max_activations' => $license->max_activations,
            ], 'client_app');

            return response()->json([
                'error' => 'activation_limit_reached',
                'message' => "Maximum activations ({$license->max_activations}) reached for this license.",
                'current_activations' => $activeCount,
            ], 409);
        }

        $activation = LicenseActivation::create([
            'license_id' => $license->id,
            'device_fingerprint' => $validated['device_fingerprint'],
            'hostname' => $validated['hostname'] ?? null,
            'ip_address' => $validated['ip_address'] ?? $request->ip(),
            'os_info' => $validated['os_info'] ?? null,
            'activated_at' => now(),
            'last_seen_at' => now(),
            'status' => 'active',
        ]);

        $token = $this->engine->generateToken($license);

        AuditLog::record('license.activated', 'activation', $activation->id, [
            'license_id' => $license->id,
            'device_fingerprint' => $validated['device_fingerprint'],
            'hostname' => $validated['hostname'] ?? null,
        ], 'client_app');

        return response()->json([
            'data' => [
                'activation_id' => $activation->id,
                'license_token' => $token,
                'activated_at' => $activation->activated_at->toISOString(),
                'entitlements' => [
                    'features' => $license->features,
                    'max_users' => $license->max_users,
                    'expires_at' => $license->expires_at->toISOString(),
                ],
                'heartbeat_interval_hours' => config('licensing.heartbeat_interval_hours'),
                'grace_period_days' => config('licensing.grace_period_days'),
            ],
        ]);
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

        // Check revocation
        $isRevoked = RevocationList::where('license_id', $license->id)
            ->where(function ($q) {
                $q->whereNull('effective_at')
                  ->orWhere('effective_at', '<=', now());
            })
            ->exists();

        if ($isRevoked || $license->status === 'revoked') {
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
        ], 'client_app');

        return response()->json([
            'data' => [
                'valid' => true,
                'status' => $license->status,
                'entitlements' => [
                    'features' => $license->features,
                    'max_users' => $license->max_users,
                    'plan' => $license->plan,
                ],
                'revoked' => false,
                'expires_at' => $license->expires_at->toISOString(),
                'days_remaining' => now()->diffInDays($license->expires_at, false),
                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Deactivate a license activation (client-initiated).
     */
    public function deactivate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'license_key' => ['required', 'string'],
            'device_fingerprint' => ['required', 'string'],
        ]);

        $license = License::where('license_key', $validated['license_key'])->first();

        if (!$license) {
            return response()->json([
                'error' => 'license_not_found',
                'message' => 'No license found with the provided key.',
            ], 404);
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
        ], 'client_app');

        return response()->json([
            'data' => [
                'deactivated' => true,
                'activation_id' => $activation->id,
                'deactivated_at' => now()->toISOString(),
            ],
        ]);
    }
}
