<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks soft-deleted or inactive accounts from using existing Sanctum tokens.
 *
 * Must run after `auth:sanctum` so `$request->user()` is resolved from the
 * Bearer token. Unauthenticated requests (e.g. POST /login) pass through —
 * auth middleware remains responsible for 401s.
 *
 * On violation: revoke ALL personal access tokens for that user, then 403.
 */
class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No authenticated principal yet — let auth:sanctum / public routes proceed.
        if ($user === null) {
            return $next($request);
        }

        /** @var User $user */
        $isSoftDeleted = method_exists($user, 'trashed') && $user->trashed();
        $isInactive = ! $user->is_active;

        if ($isSoftDeleted || $isInactive) {
            // Kill every device session so the revoked token cannot be reused.
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Your account has been deactivated. Access revoked.',
            ], 403);
        }

        return $next($request);
    }
}
