<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $clientId = $request->header('X-Client-Id');
        $clientSecret = $request->header('X-Client-Secret');

        if (!$clientId || !$clientSecret) {
            return response()->json([
                'error' => 'missing_credentials',
                'message' => 'X-Client-Id and X-Client-Secret headers are required.',
            ], 401);
        }

        $client = ApiClient::where('client_id', $clientId)
            ->where('is_active', true)
            ->first();

        if (!$client || !Hash::check($clientSecret, $client->client_secret_hash)) {
            return response()->json([
                'error' => 'invalid_credentials',
                'message' => 'Invalid client credentials.',
            ], 401);
        }

        if ($scope && !$client->hasScope($scope)) {
            return response()->json([
                'error' => 'insufficient_scope',
                'message' => "This client does not have the '{$scope}' scope.",
            ], 403);
        }

        $request->merge(['api_client' => $client]);

        return $next($request);
    }
}
