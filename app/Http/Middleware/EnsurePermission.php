<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorize the Sanctum-authenticated admin user against a spatie permission.
 *
 * Uses $request->user() (resolved by the preceding auth:sanctum middleware) and
 * the user's own can() — which routes through spatie's Gate::before hook and
 * checks the model's default ("web") guard, matching how permissions are seeded.
 * Returns the app's standard JSON error envelope instead of spatie's exception.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        if (!$user->can($permission)) {
            return response()->json([
                'error' => 'insufficient_permission',
                'message' => "This action requires the '{$permission}' permission.",
            ], 403);
        }

        // Enforce the token's own ability scope. The login token is minted with
        // exactly the user's permissions (AuthController::login), so a token may
        // not be used beyond the scope it was issued with even if the user's
        // current RBAC would otherwise allow it. tokenCan() returns true for
        // session-based (TransientToken) auth, so this is a no-op there.
        if (!$user->tokenCan($permission)) {
            return response()->json([
                'error' => 'insufficient_scope',
                'message' => "Your access token is not scoped for the '{$permission}' permission.",
            ], 403);
        }

        return $next($request);
    }
}
