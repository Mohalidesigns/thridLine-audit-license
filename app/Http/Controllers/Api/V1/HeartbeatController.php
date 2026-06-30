<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\HeartbeatRequest;
use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseUsageMetric;
use Illuminate\Http\JsonResponse;

class HeartbeatController extends Controller
{
    public function __invoke(HeartbeatRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $license = License::where('license_key', $validated['license_key'])->first();

        if (!$license) {
            return response()->json([
                'error' => 'license_not_found',
                'message' => 'No license found with the provided key.',
            ], 404);
        }

        $apiClient = $request->input('api_client');
        if (!$apiClient instanceof ApiClient || $license->org_id !== $apiClient->org_id) {
            AuditLog::record('tenant_mismatch.detected', 'license', $license->id, [
                'action' => 'heartbeat',
                'license_org_id' => $license->org_id,
                'api_client_org_id' => $apiClient?->org_id,
                'api_client_id' => $apiClient?->id,
            ], 'client_app', $apiClient?->id);

            return response()->json([
                'error' => 'tenant_mismatch',
                'message' => 'This license does not belong to your organization.',
            ], 403);
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

        // Update last seen
        $activation->update(['last_seen_at' => now()]);

        // Record usage metrics if provided
        if (isset($validated['active_users']) || isset($validated['feature_usage'])) {
            LicenseUsageMetric::create([
                'license_id' => $license->id,
                'activation_id' => $activation->id,
                'active_users_count' => $validated['active_users'] ?? 0,
                'feature_usage' => $validated['feature_usage'] ?? [],
                'reported_at' => now(),
            ]);
        }

        // Check if license is revoked (status flag OR an in-effect revocation
        // row; cancelled and future-scheduled rows are ignored)
        $isRevoked = $license->isEffectivelyRevoked();

        $commands = [];
        $updatedEntitlements = [
            'features' => $license->features,
            'max_users' => $license->max_users,
            'plan' => $license->plan,
            'type' => $license->type ?? 'full',
            'is_trial' => $license->isTrialType(),
            'expires_at' => $license->expires_at->toISOString(),
        ];

        if ($isRevoked || $license->status === 'revoked') {
            $commands[] = 'force_deactivate';
        }

        if ($license->isExpired()) {
            $commands[] = 'license_expired';
        }

        $nextHeartbeat = now()->addHours(config('licensing.heartbeat_interval_hours'));

        AuditLog::record('heartbeat.received', 'activation', $activation->id, [
            'license_id' => $license->id,
            'active_users' => $validated['active_users'] ?? null,
            'app_version' => $validated['app_version'] ?? null,
        ], 'client_app', $apiClient?->id);

        return response()->json([
            'data' => [
                'status' => $license->status,
                'revoked' => $isRevoked,
                'server_time' => now()->toISOString(),
                'next_heartbeat_before' => $nextHeartbeat->toISOString(),
                'updated_entitlements' => $updatedEntitlements,
                'commands' => $commands,
                'grace_period_days' => $license->gracePeriodDays(),
            ],
        ]);
    }
}
