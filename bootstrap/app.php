<?php

use App\Http\Middleware\AuthenticateApiClient;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\IpAllowlist;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.client' => AuthenticateApiClient::class,
            'ip.allowlist' => IpAllowlist::class,
            'perm' => EnsurePermission::class,
        ]);

        // The admin SPA authenticates with bearer tokens (Sanctum personal access
        // tokens), not session cookies — so we intentionally do NOT enable
        // statefulApi(). Enabling it would apply session + CSRF to /api/v1/*,
        // and the token-based fetch() calls (no X-XSRF-TOKEN) would get 419s.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
