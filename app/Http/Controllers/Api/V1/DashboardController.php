<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $activeLicenses = License::where('status', 'active')->count();
        $expiringSoon = License::where('status', 'active')
            ->where('expires_at', '<=', now()->addDays(30))
            ->where('expires_at', '>', now())
            ->count();
        $revokedThisMonth = License::where('status', 'revoked')
            ->where('updated_at', '>=', now()->startOfMonth())
            ->count();
        $totalOrgs = Organization::count();
        $totalActivations = LicenseActivation::where('status', 'active')->count();

        return response()->json([
            'data' => [
                'active_licenses' => $activeLicenses,
                'expiring_soon' => $expiringSoon,
                'revoked_this_month' => $revokedThisMonth,
                'total_organizations' => $totalOrgs,
                'total_active_activations' => $totalActivations,
                'licenses_by_plan' => License::where('status', 'active')
                    ->selectRaw('plan, count(*) as count')
                    ->groupBy('plan')
                    ->pluck('count', 'plan'),
            ],
        ]);
    }

    public function recentActivity(): JsonResponse
    {
        $logs = AuditLog::orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_type' => $log->actor_type,
                'resource_type' => $log->resource_type,
                'resource_id' => $log->resource_id,
                'created_at' => $log->created_at->toISOString(),
                'metadata' => $log->metadata,
            ]);

        return response()->json(['data' => $logs]);
    }
}
