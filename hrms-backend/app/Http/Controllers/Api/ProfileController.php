<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee read-only profile dashboard data provider.
 *
 * PRD (info.md): Employees have read-only access to their own profile and may
 * only see colleagues sharing the same team_id. This endpoint scopes ALL data
 * to the authenticated Sanctum token holder — never accepts a user ID parameter.
 *
 * Route middleware (when wired): auth:sanctum (Admin and Employee both permitted).
 */
class ProfileController extends Controller
{
    /**
     * Return the authenticated user's profile with team-scoped colleague roster.
     *
     * Scoping logic:
     * 1. Identity is resolved exclusively from the Bearer token ($request->user()).
     *    No route parameter can substitute another user's ID — prevents IDOR.
     * 2. team relationship loads the employee's assigned organizational unit.
     * 3. team.users loads ONLY colleagues sharing the same team_id FK (ERD 1:M).
     *    This roster defines who the Employee may submit attendance for (self + team).
     * 4. If team_id is NULL, team and colleagues are absent — employee has no team scope.
     *
     * Sensitive field protection:
     * - User::$hidden excludes `password` from JSON on the profile AND nested team.users.
     * - Sanctum tokens relation is NOT eager-loaded — token hashes never serialized.
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "message": "Profile retrieved successfully.",
     *   "data": {
     *     "id": 5,
     *     "name": "Jane Doe",
     *     "email": "jane@example.com",
     *     "job_title": "Employee",
     *     "team_id": 2,
     *     "team": {
     *       "id": 2,
     *       "team_name": "Engineering",
     *       "users": [
     *         { "id": 5, "name": "Jane Doe", "team_id": 2 },
     *         { "id": 7, "name": "John Smith", "team_id": 2 }
     *       ]
     *     }
     *   }
     * }
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();
        $currentYear = (int) date('Y');

        // Re-fetch with eager loading to prevent N+1 when serializing team + colleagues.
        // findOrFail on own ID preserves token-scoped identity — same user, enriched relations.
        $profile = User::query()
            ->with([
                // Load assigned team container (null when team_id is unset).
                'team',
                // Load colleague roster scoped implicitly by teams.id → users.team_id (ERD).
                // Active-only filter: deactivated accounts excluded from attendance submission UI.
                'team.users' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('name'),
                // Current-year leave balances for employee dashboard stat cards.
                'yearlyLeaveRecords' => static function ($query) use ($currentYear): void {
                    $query
                        ->where('year', $currentYear)
                        ->with('leaveType');
                },
            ])
            ->findOrFail($authenticatedUser->id);

        // Defense-in-depth: confirm $hidden strips credentials before JSON encoding.
        // User model $hidden = ['password'] — nested team.users inherit the same rule.
        $profile->makeHidden(['password']);

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => $profile,
        ], 200);
    }
}
