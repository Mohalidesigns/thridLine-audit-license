<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LicenseActivation;
use App\Models\LicenseUsageMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin registry of every activated deployment: where it runs (domain/host),
 * what it runs (version/env), which license backs it, and whether its
 * heartbeat is healthy. A deployment is "stale" when it hasn't checked in for
 * more than twice the heartbeat interval — the same threshold the
 * licenses:heartbeat-alerts command uses.
 */
class DeploymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $staleAfterHours = 2 * (int) config('licensing.heartbeat_interval_hours', 48);
        $staleBefore = now()->subHours($staleAfterHours);

        $deployments = LicenseActivation::with(['license.organization'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('org_id'), fn ($q, $orgId) => $q->whereHas('license', fn ($q) => $q->where('org_id', $orgId)))
            ->when($request->query('search'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('domain', 'like', "%{$search}%")
                      ->orWhere('hostname', 'like', "%{$search}%")
                      ->orWhereHas('license.organization', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->query('stale') === '1', fn ($q) => $q->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $staleBefore)))
            ->orderByDesc('last_seen_at')
            ->paginate($request->query('per_page', 25));

        $deployments->getCollection()->transform(function (LicenseActivation $a) use ($staleBefore) {
            return [
                'id' => $a->id,
                'organization' => $a->license?->organization?->name,
                'org_id' => $a->license?->org_id,
                'license_key' => $a->license?->license_key,
                'plan' => $a->license?->plan,
                'type' => $a->license?->type,
                'license_status' => $a->license?->status,
                'domain' => $a->domain,
                'hostname' => $a->hostname,
                'ip_address' => $a->ip_address,
                'os_info' => $a->os_info,
                'app_version' => $a->app_version,
                'app_env' => $a->app_env,
                'status' => $a->status,
                'activated_at' => $a->activated_at?->toISOString(),
                'last_seen_at' => $a->last_seen_at?->toISOString(),
                'is_stale' => $a->status === 'active'
                    && ($a->last_seen_at === null || $a->last_seen_at->lt($staleBefore)),
            ];
        });

        return response()->json($deployments);
    }

    /**
     * Heartbeat/telemetry history for one deployment (most recent first).
     */
    public function heartbeats(LicenseActivation $activation): JsonResponse
    {
        $metrics = LicenseUsageMetric::where('activation_id', $activation->id)
            ->orderByDesc('reported_at')
            ->limit(100)
            ->get(['active_users_count', 'feature_usage', 'reported_at']);

        return response()->json([
            'data' => [
                'activation_id' => $activation->id,
                'last_seen_at' => $activation->last_seen_at?->toISOString(),
                'heartbeats' => $metrics->map(fn ($m) => [
                    'active_users' => $m->active_users_count,
                    'feature_usage' => $m->feature_usage,
                    'reported_at' => $m->reported_at?->toISOString(),
                ]),
            ],
        ]);
    }

    /**
     * Admin-approved fingerprint reset: release one activation slot (e.g. the
     * customer replaced the server). The old device locks itself on its next
     * validate/heartbeat (activation_not_found); the freed slot lets the new
     * machine activate with the same license key — the license is untouched.
     */
    public function deactivate(Request $request, LicenseActivation $activation): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($activation->status !== 'active') {
            return response()->json([
                'error' => 'activation_not_active',
                'message' => 'This activation has already been deactivated.',
            ], 409);
        }

        $activation->update([
            'status' => 'deactivated',
            'deactivated_at' => now(),
        ]);

        AuditLog::record('activation.reset', 'activation', $activation->id, [
            'license_id' => $activation->license_id,
            'device_fingerprint' => $activation->device_fingerprint,
            'domain' => $activation->domain,
            'hostname' => $activation->hostname,
            'reason' => $validated['reason'] ?? null,
            'initiated_by' => 'admin',
        ]);

        return response()->json([
            'data' => [
                'activation_id' => $activation->id,
                'status' => 'deactivated',
                'deactivated_at' => $activation->deactivated_at->toISOString(),
                'freed_slots' => max(0, $activation->license->max_activations
                    - $activation->license->activeActivations()->count()),
            ],
        ]);
    }
}
