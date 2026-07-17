<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal profile / dashboard data provider (Admin + Employee).
 *
 * PRD (info.md): Callers have read-only access to their own profile and may
 * only see colleagues sharing the same team_id. This endpoint scopes ALL data
 * to the authenticated Sanctum token holder — never accepts a user ID parameter.
 *
 * Route middleware: auth:sanctum + role:Admin,Employee.
 */
class ProfileController extends Controller
{
    /**
     * Return the authenticated user's profile, current-year leave balances,
     * calculated total absences, and team-scoped colleague roster with
     * date-filtered attendance logs.
     *
     * Query: ?date=YYYY-MM-DD (defaults to today). Past dates are read-only
     * on the dashboard — submissions for non-today dates are blocked on POST.
     *
     * Scoping logic:
     * 1. Identity is resolved exclusively from the Bearer token ($request->user()).
     *    No route parameter can substitute another user's ID — prevents IDOR.
     * 2. leave_balances are loaded for the current calendar year with leaveType.
     * 3. total_absences is the sum of taken_days across those balance rows
     *    (fractional codes like AO/OA are already reflected in taken_days).
     * 4. team.users loads ONLY colleagues sharing the same team_id FK (ERD 1:M),
     *    with attendance_logs eager-loaded for the requested $date only.
     *
     * JSON response (200) data shape (key fields):
     * {
     *   "id": 5,
     *   "name": "Jane Doe",
     *   "attendance_date": "2026-07-17",
     *   "team": { "id": 2, "team_name": "Engineering", "users": [{ ..., "attendance_logs": [...] }] },
     *   "leave_balances": [ { "leave_type_id": 1, "assigned_days": 14, "taken_days": 2, ... } ],
     *   "total_absences": 2.0,
     *   "yearly_leave_records": [ ... ] // same as leave_balances for existing SPA clients
     * }
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $currentYear = (int) now()->year;
        $date = $validated['date'] ?? now()->toDateString();

        // Current-year leave balances for dashboard leave cards (dynamic, not hardcoded).
        $leaveBalances = UserYearlyLeaveRecord::query()
            ->with('leaveType')
            ->where('user_id', $user->id)
            ->where('year', $currentYear)
            ->orderBy('leave_type_id')
            ->get();

        // Total absences = cumulative taken_days for the year (supports half-day deductions).
        // Domain has no attendance_logs.status='Absent'; leave usage lives on balance rows.
        $totalAbsences = round((float) $leaveBalances->sum('taken_days'), 2);

        // Re-fetch profile with team + colleagues; balances are attached explicitly below.
        // Attendance is date-scoped so the SPA can browse past days without loading history.
        $profile = User::query()
            ->with([
                'team',
                'team.users' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->with([
                        'attendanceLogs' => static function ($attendanceQuery) use ($date): void {
                            $attendanceQuery
                                ->whereDate('date', $date)
                                ->with([
                                    'leaveType' => fn ($leaveQuery) => $leaveQuery->withTrashed(),
                                ]);
                        },
                    ]),
            ])
            ->findOrFail($user->id);

        $profile->makeHidden(['password']);

        $payload = $profile->toArray();
        $payload['attendance_date'] = $date;
        $payload['leave_balances'] = $leaveBalances;
        $payload['total_absences'] = $totalAbsences;
        // Backward-compatible alias used by existing Admin/Employee Dashboard clients.
        $payload['yearly_leave_records'] = $leaveBalances;

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => $payload,
        ], 200);
    }
}
