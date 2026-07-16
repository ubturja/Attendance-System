<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Sanctum token authentication controller for the HRMS REST API.
 *
 * Exposes a single unified login endpoint consumed by the React SPA.
 * Successful login returns a Bearer token plus the authenticated user
 * (including job_title) for immediate client-side RBAC routing.
 */
class AuthController extends Controller
{
    /**
     * Authenticate credentials and issue a personal access token.
     *
     * Role is never accepted from the request — RBAC is derived solely from
     * the authenticated user's persisted job_title (Admin | Employee).
     *
     * Flow:
     * 1. LoginRequest validates email/password only (422 on failure).
     * 2. Resolve user by email — generic 401 if not found (prevents user enumeration).
     * 3. Verify bcrypt hash via Hash::check (never compare plaintext in SQL).
     * 4. Reject deactivated accounts before token issuance.
     * 5. Mint a Sanctum personal access token for API guard (auth:sanctum).
     * 6. Return token + authenticated user (job_title drives frontend routing).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated();

        // Lookup by unique email index — single query before credential verification.
        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        // Uniform 401 for unknown email OR wrong password (security: no account enumeration).
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        // Block token issuance for deactivated HRMS accounts (is_active ERD flag).
        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been temporarily deactivated. Contact the admin for further information.',
            ], 403);
        }

        // createToken persists a row in personal_access_tokens; plainTextToken is shown once to the client.
        $token = $user->createToken('hrms-api-token')->plainTextToken;

        // password is already excluded via User::$hidden; makeHidden is defense-in-depth.
        $user->makeHidden(['password']);

        return response()->json([
            'success' => true,
            'message' => 'Authentication successful.',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                // Full user object — job_title is the RBAC discriminator for SPA routing.
                'user' => $user,
            ],
        ], 200);
    }

    /**
     * Revoke the Bearer token used for the current request.
     *
     * Requires auth:sanctum middleware — $request->user() is resolved from
     * the Authorization header. Deletes only the current token row, leaving
     * other device sessions intact if multiple tokens exist.
     */
    public function logout(Request $request): JsonResponse
    {
        // currentAccessToken() returns the PersonalAccessToken model matched to this request.
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Successfully logged out.',
        ], 200);
    }
}
