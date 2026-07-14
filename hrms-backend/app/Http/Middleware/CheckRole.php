<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC middleware for the HRMS API.
 *
 * Enforces route-level access control by comparing the authenticated user's
 * `job_title` (ERD ENUM: Admin | Employee) against a required role passed
 * as a middleware parameter. Must be applied after `auth:sanctum` so that
 * `$request->user()` resolves from the Bearer token.
 *
 * Usage (when routes are defined): `->middleware(['auth:sanctum', 'role:Admin'])`
 */
class CheckRole
{
    /**
     * Verify the authenticated user's job_title matches the required role.
     *
     * Role verification logic:
     * 1. Resolve the authenticated user from the Sanctum token (expects prior auth middleware).
     * 2. Compare `job_title` to the `$role` route parameter using strict equality.
     * 3. Abort with 403 Forbidden JSON if the values differ — no controller execution.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $role  Required job_title value (e.g., "Admin", "Employee").
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        // Unauthenticated requests should be blocked by auth:sanctum first; guard defensively.
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Strict comparison against ERD job_title ENUM — Admin vs Employee RBAC gate.
        if ($user->job_title !== $role) {
            return $this->forbiddenResponse('Forbidden. Insufficient role privileges.');
        }

        return $next($request);
    }

    /**
     * Standardized 403 JSON envelope for predictable SPA error handling.
     */
    private function forbiddenResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }
}
