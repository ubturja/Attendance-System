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
     * Bulk upsert assigned_days for a user's leave balance rows in a calendar year.
     *
     * Route model binding resolves {user} → User. Uses updateOrCreate on the composite key
     * (user_id, leave_type_id, year) so mid-year leave type additions succeed without a
     * pre-existing row — common when Admin adds a new leave category after user provisioning.
     *
     * UpdateLeaveAllocationRequest validates year and an allocations[] of leave_type_id +
     * assigned_days. Existing taken_days are preserved; remaining_days is computed via the
     * model accessor (assigned_days − taken_days).
     *
     * Business guard: taken_days must not exceed the new assigned_days for any row.
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "message": "Leave allocations updated successfully.",
     *   "data": [
     *     {
     *       "id": 1,
     *       "user_id": 5,
     *       "leave_type_id": 1,
     *       "year": 2026,
     *       "assigned_days": 14.5,
     *       "taken_days": 3.5,
     *       "remaining_days": 11.0,
     *       "..."
     *     }
     *   ]
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
        $year = (int) $validated['year'];
        /** @var list<array{leave_type_id: int, assigned_days: numeric-string|float|int}> $allocations */
        $allocations = $validated['allocations'];

        // Pre-flight: reject any quota reduction that would leave taken_days above assigned_days.
        foreach ($allocations as $allocation) {
            $leaveTypeId = (int) $allocation['leave_type_id'];
            $assignedDays = (float) $allocation['assigned_days'];

            $existing = UserYearlyLeaveRecord::query()
                ->where('user_id', $user->id)
                ->where('leave_type_id', $leaveTypeId)
                ->where('year', $year)
                ->first();

            $takenDays = (float) ($existing?->taken_days ?? 0);

            if ($takenDays > $assignedDays) {
                return response()->json([
                    'success' => false,
                    'message' => 'taken_days cannot exceed assigned_days.',
                ], 422);
            }
        }

        // Atomic bulk upsert — only assigned_days is written; taken_days stays untouched.
        $records = DB::transaction(function () use ($user, $year, $allocations): array {
            $updated = [];

            foreach ($allocations as $allocation) {
                $record = UserYearlyLeaveRecord::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'leave_type_id' => (int) $allocation['leave_type_id'],
                        'year' => $year,
                    ],
                    [
                        'assigned_days' => (float) $allocation['assigned_days'],
                    ]
                );

                // remaining_days is computed by the model accessor from assigned_days − taken_days.
                $record->load('leaveType');
                $updated[] = $record;
            }

            return $updated;
        });

        return response()->json([
            'success' => true,
            'message' => 'Leave allocations updated successfully.',
            'data' => $records,
        ], 200);
    }
}

