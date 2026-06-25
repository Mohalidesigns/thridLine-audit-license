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

    // Public auth
    Route::post('auth/login', [AuthController::class, 'login']);

    // Client-facing endpoints (API client auth via X-Client-Id / X-Client-Secret)
    Route::middleware(['api.client'])->group(function () {
        Route::post('licenses/activate', [ActivationController::class, 'activate']);
        Route::post('licenses/validate', [ActivationController::class, 'validate']);
        Route::post('licenses/deactivate', [ActivationController::class, 'deactivate']);
        Route::post('licenses/heartbeat', HeartbeatController::class);
    });

    // Admin endpoints (Sanctum auth)
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Dashboard
        Route::get('dashboard/stats', [DashboardController::class, 'stats']);
        Route::get('dashboard/recent-activity', [DashboardController::class, 'recentActivity']);

        // Organizations
        Route::apiResource('organizations', OrganizationController::class);

        // Licenses
        Route::get('licenses', [LicenseController::class, 'index']);
        Route::get('licenses/{license}', [LicenseController::class, 'show']);
        Route::post('licenses', [LicenseController::class, 'store']);
        Route::put('licenses/{license}', [LicenseController::class, 'update']);
        Route::post('licenses/revoke', [LicenseController::class, 'revoke']);
        Route::post('licenses/revoke/cancel', [LicenseController::class, 'cancelRevoke']);
        Route::post('licenses/{license}/renew', [LicenseController::class, 'renew']);
        Route::post('licenses/{license}/transfer', [LicenseController::class, 'transfer']);
        Route::get('licenses/{license}/download-file', [LicenseController::class, 'downloadFile']);
        Route::get('licenses/{license}/generate-file', [LicenseController::class, 'generateFile']);
        Route::post('licenses/{license}/offline-activate', [LicenseController::class, 'offlineActivate']);

        // API Clients
        Route::get('api-clients', [ApiClientController::class, 'index']);
        Route::post('api-clients', [ApiClientController::class, 'store']);
        Route::put('api-clients/{apiClient}', [ApiClientController::class, 'update']);
        Route::post('api-clients/{apiClient}/regenerate-secret', [ApiClientController::class, 'regenerateSecret']);

        // Audit Logs
        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
    });
});
