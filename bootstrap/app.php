<?php

use App\Http\Middleware\AuthenticateApiClient;
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
        ]);

        $middleware->statefulApi();

        // Behind the host nginx reverse proxy (localhost-bound container); honor
        // X-Forwarded-* so Laravel knows requests arrive over HTTPS. Only the host
        // proxy can reach 127.0.0.1:8088, so trusting all forwarded headers is safe.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
