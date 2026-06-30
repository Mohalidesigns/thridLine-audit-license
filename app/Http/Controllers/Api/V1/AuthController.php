<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        // Credential check + email/IP rate limiting live in LoginRequest,
        // mirroring ThirdLine's mechanism. Returns the authenticated user.
        $user = $request->authenticate();

        // Scope the token to the user's permissions instead of a wildcard.
        $abilities = $user->getAllPermissions()->pluck('name')->all() ?: ['none'];
        $token = $user->createToken('api-token', $abilities)->plainTextToken;

        AuditLog::record('user.login', 'user', $user->id, null, 'admin', $user->id);

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLog::record('user.logout', 'user', $request->user()->id);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
        ]);
    }
}
