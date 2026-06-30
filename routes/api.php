<?php

use App\Http\Controllers\Api\V1\ActivationController;
use App\Http\Controllers\Api\V1\ApiClientController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\LicenseController;
use App\Http\Controllers\Api\V1\OrganizationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — v1
|--------------------------------------------------------------------------
*/

// Health check (no auth required)
Route::get('v1/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'thirdline-licensing-server',
        'version' => config('app.version', '1.0.0'),
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::prefix('v1')->group(function () {

    // Public auth — brute-force protection is handled inside LoginRequest via an
    // email+IP RateLimiter (mirrors ThirdLine), so no blunt route-level throttle
    // that would also penalise successful logins.
    Route::post('auth/login', [AuthController::class, 'login']);

    // Client-facing endpoints (API client auth via X-Client-Id / X-Client-Secret).
    // Each route declares its required scope; ip.allowlist enforces the client's
    // source-IP allowlist (no-op when allowed_ips is null).
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('licenses/activate', [ActivationController::class, 'activate'])
            ->middleware('api.client:license:activate', 'ip.allowlist');
        Route::post('licenses/validate', [ActivationController::class, 'validate'])
            ->middleware('api.client:license:validate', 'ip.allowlist');
        Route::post('licenses/deactivate', [ActivationController::class, 'deactivate'])
            ->middleware('api.client:license:deactivate', 'ip.allowlist');
        Route::post('licenses/heartbeat', HeartbeatController::class)
            ->middleware('api.client:license:heartbeat', 'ip.allowlist');
    });

    // Admin endpoints (Sanctum auth + spatie RBAC per route).
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Dashboard
        Route::get('dashboard/stats', [DashboardController::class, 'stats'])->middleware('perm:dashboard.view');
        Route::get('dashboard/recent-activity', [DashboardController::class, 'recentActivity'])->middleware('perm:dashboard.view');

        // Organizations
        Route::get('organizations', [OrganizationController::class, 'index'])->middleware('perm:organizations.view');
        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->middleware('perm:organizations.view');
        Route::post('organizations', [OrganizationController::class, 'store'])->middleware('perm:organizations.create');
        Route::put('organizations/{organization}', [OrganizationController::class, 'update'])->middleware('perm:organizations.update');
        Route::patch('organizations/{organization}', [OrganizationController::class, 'update'])->middleware('perm:organizations.update');
        Route::delete('organizations/{organization}', [OrganizationController::class, 'destroy'])->middleware('perm:organizations.delete');

        // Licenses
        Route::get('licenses', [LicenseController::class, 'index'])->middleware('perm:licenses.view');
        Route::get('licenses/{license}', [LicenseController::class, 'show'])->middleware('perm:licenses.view');
        Route::post('licenses', [LicenseController::class, 'store'])->middleware('perm:licenses.create');
        Route::put('licenses/{license}', [LicenseController::class, 'update'])->middleware('perm:licenses.update');
        Route::post('licenses/revoke', [LicenseController::class, 'revoke'])->middleware('perm:licenses.revoke');
        Route::post('licenses/{license}/renew', [LicenseController::class, 'renew'])->middleware('perm:licenses.update');
        Route::post('licenses/{license}/transfer', [LicenseController::class, 'transfer'])->middleware('perm:licenses.update');
        Route::get('licenses/{license}/download-file', [LicenseController::class, 'downloadFile'])->middleware('perm:licenses.view');
        Route::get('licenses/{license}/generate-file', [LicenseController::class, 'generateFile'])->middleware('perm:licenses.view');
        Route::post('licenses/{license}/offline-activate', [LicenseController::class, 'offlineActivate'])->middleware('perm:licenses.create');

        // API Clients
        Route::get('api-clients', [ApiClientController::class, 'index'])->middleware('perm:api-clients.view');
        Route::post('api-clients', [ApiClientController::class, 'store'])->middleware('perm:api-clients.create');
        Route::put('api-clients/{apiClient}', [ApiClientController::class, 'update'])->middleware('perm:api-clients.update');
        Route::post('api-clients/{apiClient}/regenerate-secret', [ApiClientController::class, 'regenerateSecret'])->middleware('perm:api-clients.regenerate-secret');

        // Audit Logs
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('perm:audit-logs.view');
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('perm:audit-logs.view');
    });
});
