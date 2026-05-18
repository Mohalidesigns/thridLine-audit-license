<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clients = ApiClient::with('organization')
            ->when($request->query('org_id'), fn ($q, $orgId) => $q->where('org_id', $orgId))
            ->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 15));

        return response()->json($clients);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'org_id' => ['required', 'uuid', 'exists:organizations,id'],
            'allowed_scopes' => ['required', 'array'],
            'allowed_scopes.*' => ['string', 'in:license:validate,license:activate,license:heartbeat,license:*'],
            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => ['ip'],
        ]);

        $clientId = 'apgrc_' . Str::random(32);
        $clientSecret = Str::random(64);

        $client = ApiClient::create([
            'org_id' => $validated['org_id'],
            'client_id' => $clientId,
            'client_secret_hash' => Hash::make($clientSecret),
            'allowed_scopes' => $validated['allowed_scopes'],
            'allowed_ips' => $validated['allowed_ips'] ?? null,
        ]);

        AuditLog::record('api_client.created', 'api_client', $client->id, [
            'org_id' => $client->org_id,
            'scopes' => $client->allowed_scopes,
        ]);

        return response()->json([
            'data' => [
                'id' => $client->id,
                'client_id' => $clientId,
                'client_secret' => $clientSecret, // Only shown once
                'allowed_scopes' => $client->allowed_scopes,
                'allowed_ips' => $client->allowed_ips,
                'message' => 'Store the client_secret securely. It will not be shown again.',
            ],
        ], 201);
    }

    public function update(Request $request, ApiClient $apiClient): JsonResponse
    {
        $validated = $request->validate([
            'allowed_scopes' => ['sometimes', 'array'],
            'allowed_scopes.*' => ['string', 'in:license:validate,license:activate,license:heartbeat,license:*'],
            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => ['ip'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $apiClient->update($validated);

        AuditLog::record('api_client.updated', 'api_client', $apiClient->id, $validated);

        return response()->json(['data' => $apiClient->fresh()]);
    }

    public function regenerateSecret(ApiClient $apiClient): JsonResponse
    {
        $newSecret = Str::random(64);
        $apiClient->update(['client_secret_hash' => Hash::make($newSecret)]);

        AuditLog::record('api_client.secret_regenerated', 'api_client', $apiClient->id);

        return response()->json([
            'data' => [
                'client_id' => $apiClient->client_id,
                'client_secret' => $newSecret,
                'message' => 'Store the new client_secret securely. It will not be shown again.',
            ],
        ]);
    }
}
