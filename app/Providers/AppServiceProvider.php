<?php

namespace App\Providers;

use App\Services\Licensing\LicenseEngine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LicenseEngine::class, function () {
            return new LicenseEngine();
        });
    }

    public function boot(): void
    {
        RateLimiter::for('license-api', function (Request $request) {
            return [
                Limit::perMinute(60)->by($request->bearerToken() ?? $request->ip()),
                Limit::perMinute(10)->by($request->ip())->response(function () {
                    return response()->json([
                        'error' => 'rate_limit_exceeded',
                        'retry_after' => 60,
                    ], 429);
                }),
            ];
        });

        RateLimiter::for('activation', function (Request $request) {
            return Limit::perHour(5)->by(
                ($request->input('license_key') ?? '') . '|' . $request->ip()
            );
        });
    }
}
