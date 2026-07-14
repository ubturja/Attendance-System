<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexLeaveAllocationRequest;
use App\Http\Requests\Admin\UpdateLeaveAllocationRequest;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Admin leave allocation API for yearly balance management.
 *
 * Secured at the route layer via auth:sanctum + role:Admin middleware.
 * SystemArchitecture.md: remaining_days is NEVER persisted — computed via the
 * UserYearlyLeaveRecord remaining_days accessor on every JSON response.
 *
 * show/update routes bind {user} — balances are fetched and adjusted in the
 * context of a specific employee, not by balance-row primary key.
 */
class LeaveAllocationController extends Controller
{
    /**
     * Fetch all leave balance rows for a user in a given calendar year.
     *
     * Query params (validated by IndexLeaveAllocationRequest):
     *   user_id — required, exists:users,id
     *   year    — required, 4-digit calendar year (e.g., 2026)
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "message": "Leave allocations retrieved successfully.",
     *   "data": [
     *     {
     *       "id": 1,
     *       "user_id": 5,
     *       "leave_type_id": 1,
     *       "year": 2026,
     *       "assigned_days": 14.5,
     *       "taken_days": 2.0,
     *       "remaining_days": 12.5,
     *       "leave_type": { "id": 1, "leave_type_code": "A", "name": "Annual Leave", "is_active": true }
     *     }
     *   ]
     * }
     *
     * remaining_days is computed dynamically: assigned_days − taken_days (not stored).
     */
    public function index(IndexLeaveAllocationRequest $request): JsonResponse
    {
        $filters = $request->validated();

        // Retrieve all balance rows for the scoped user/year with leave type metadata.
        $records = UserYearlyLeaveRecord::query()
            ->with('leaveType')
            ->where('user_id', $filters['user_id'])
            ->where('year', $filters['year'])
            ->orderBy('leave_type_id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Leave allocations retrieved successfully.',
            'data' => $records,
        ], 200);
    }

    /**
     * Fetch all yearly leave balance rows for a bound user.
     *
     * Route model binding resolves {user} → User. Returns every
     * user_yearly_leave_records row for $user->id (all years and leave types).
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "message": "Leave allocations retrieved successfully.",
     *   "data": {
     *     "user_id": 5,
     *     "user_name": "Jane Doe",
     *     "records": [
     *       {
     *         "id": 1,
     *         "leave_type_id": 1,
     *         "year": 2026,
     *         "assigned_days": 14.5,
     *         "taken_days": 2.0,
     *         "remaining_days": 12.5,
     *         "leave_type": { "..." }
     *       }
     *     ]
     *   }
     * }
     */
    public function show(User $user): JsonResponse
    {
        // Eager-load leave types for all balance rows belonging to this user — no N+1.
        $records = UserYearlyLeaveRecord::query()
            ->with('leaveType')
            ->where('user_id', $user->id)
            ->orderBy('year')
            ->orderBy('leave_type_id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Leave allocations retrieved successfully.',
            'data' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'records' => $records,
            ],
        ], 200);
    }

    /**
     * Upsert assigned_days and/or taken_days on a user's balance row for a leave type/year.
     *
     * Route model binding resolves {user} → User. Uses updateOrCreate on the composite key
     * (user_id, leave_type_id, year) so mid-year leave type additions succeed without a
     * pre-existing row — common when Admin adds a new leave category after user provisioning.
     *
     * UpdateLeaveAllocationRequest validates leave_type_id, year, and balance fields.
     * Business guard: taken_days must not exceed assigned_days after merge.
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "message": "Leave allocation updated successfully.",
     *   "data": {
     *     "id": 1,
     *     "user_id": 5,
     *     "assigned_days": 14.5,
     *     "taken_days": 3.5,
     *     "remaining_days": 11.0,
     *     "..."
     *   }
     * }
     *
     * Business rule violation (422):
     * {
     *   "success": false,
     *   "message": "taken_days cannot exceed assigned_days."
     * }
     */
    public function update(UpdateLeaveAllocationRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        // Resolve existing row (if any) to support partial PATCH merges before upsert.
        $existing = UserYearlyLeaveRecord::query()
            ->where('user_id', $user->id)
            ->where('leave_type_id', $validated['leave_type_id'])
            ->where('year', $validated['year'])
            ->first();

        // Merge request values with existing row; default missing fields to 0 on create.
        $assignedDays = array_key_exists('assigned_days', $validated)
            ? (float) $validated['assigned_days']
            : (float) ($existing?->assigned_days ?? 0);

        $takenDays = array_key_exists('taken_days', $validated)
            ? (float) $validated['taken_days']
            : (float) ($existing?->taken_days ?? 0);

        // Integrity check: consumption cannot surpass allocation.
        // remaining_days formula: assignedDays − takenDays must be ≥ 0.
        if ($takenDays > $assignedDays) {
            return response()->json([
                'success' => false,
                'message' => 'taken_days cannot exceed assigned_days.',
            ], 422);
        }

        // Atomic upsert — creates row for new leave types or updates an existing allocation.
        $leaveAllocation = DB::transaction(function () use ($user, $validated, $assignedDays, $takenDays): UserYearlyLeaveRecord {
            return UserYearlyLeaveRecord::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'leave_type_id' => $validated['leave_type_id'],
                    'year' => $validated['year'],
                ],
                [
                    'assigned_days' => $assignedDays,
                    'taken_days' => $takenDays,
                ]
            );
        });

        $leaveAllocation->load('leaveType');

        return response()->json([
            'success' => true,
            'message' => 'Leave allocation updated successfully.',
            'data' => $leaveAllocation,
        ], 200);
    }
}
