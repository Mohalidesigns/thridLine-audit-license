<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->get('api_client');

        if ($client && $client->allowed_ips && !in_array($request->ip(), $client->allowed_ips)) {
            AuditLog::record('ip_blocked', 'api_client', $client->id, [
                'attempted_ip' => $request->ip(),
                'allowed_ips'  => $client->allowed_ips,
            ]);

            return response()->json([
                'error' => 'ip_not_allowed',
                'message' => 'IP address not in allowlist.',
            ], 403);
        }

        return $next($request);
    }
}
