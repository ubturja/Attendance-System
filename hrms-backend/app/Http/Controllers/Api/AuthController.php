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
 * Exposes stateless login/logout endpoints consumed by the React SPA.
 * Successful login returns a Bearer token plus the user's job_title for
 * immediate client-side RBAC routing (Admin vs Employee).
 */
class AuthController extends Controller
{
    /**
     * Authenticate credentials and issue a personal access token.
     *
     * Flow:
     * 1. LoginRequest validates email/password shape (422 on failure).
     * 2. Resolve user by email — generic 401 if not found (prevents user enumeration timing leaks when paired with hash check).
     * 3. Verify bcrypt hash via Hash::check (never compare plaintext in SQL).
     * 4. Reject deactivated accounts before token issuance.
     * 5. Mint a Sanctum personal access token for API guard (auth:sanctum).
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
                'success' => false,
                'message' => 'This account has been deactivated.',
            ], 401);
        }

        // createToken persists a row in personal_access_tokens; plainTextToken is shown once to the client.
        $token = $user->createToken('hrms-api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Authentication successful.',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'job_title' => $user->job_title,
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
