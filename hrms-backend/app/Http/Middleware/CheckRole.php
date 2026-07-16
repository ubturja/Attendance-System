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
 * `job_title` (ERD ENUM: Admin | Employee) against one or more allowed roles
 * passed as middleware parameters. Must be applied after `auth:sanctum` so that
 * `$request->user()` resolves from the Bearer token.
 *
 * Usage:
 *   ->middleware(['auth:sanctum', 'role:Admin'])
 *   ->middleware(['auth:sanctum', 'role:Admin,Employee'])
 */
class CheckRole
{
    /**
     * Verify the authenticated user's job_title is in the allowed role set.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles  One or more allowed job_title values (e.g. Admin, Employee).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Unauthenticated requests should be blocked by auth:sanctum first; guard defensively.
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $allowedRoles = array_values(array_filter(
            array_map(static fn (string $role): string => trim($role), $roles),
            static fn (string $role): bool => $role !== '',
        ));

        if ($allowedRoles === []) {
            return $this->forbiddenResponse('Forbidden. Insufficient role privileges.');
        }

        // Strict comparison against ERD job_title ENUM — multi-role routes accept any match.
        if (! in_array($user->job_title, $allowedRoles, true)) {
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
