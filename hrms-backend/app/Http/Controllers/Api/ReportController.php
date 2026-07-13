<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\DailyReportRequest;
use App\Http\Requests\Reports\MonthlyReportRequest;
use App\Http\Requests\Reports\YearlyReportRequest;
use App\Models\AttendanceLog;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Admin pivot reporting engine — daily, monthly, and yearly aggregation APIs.
 *
 * Secured at the route layer via auth:sanctum + role:Admin middleware.
 * All endpoints strictly eager-load relationships via with() to prevent N+1 queries.
 */
class ReportController extends Controller
{
    /**
     * Daily report — attendance entries for a single calendar date.
     *
     * PRD: List all users with attendance for the date, sorted by team name.
     * Eager loads user, team (snapshot), and leaveType in a single query batch.
     *
     * Query: ?date=2026-06-26
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "message": "Daily report generated successfully.",
     *   "data": {
     *     "date": "2026-06-26",
     *     "records": [
     *       {
     *         "id": 1,
     *         "user_id": 5,
     *         "user_name": "Jane Doe",
     *         "team_id": 2,
     *         "team_name": "Engineering",
     *         "date": "2026-06-26",
     *         "leave_type_code": "A",
     *         "leave_type_name": "Annual Leave"
     *       }
     *     ]
     *   }
     * }
     */
    public function daily(DailyReportRequest $request): JsonResponse
    {
        $date = $request->validated('date');

        // Single query with eager-loaded user, team snapshot, and leave type — no N+1.
        $logs = AttendanceLog::query()
            ->with(['user', 'team', 'leaveType'])
            ->whereDate('date', $date)
            ->get()
            // Sort by team name then user name (PRD: sorted by Team Name).
            ->sortBy(fn (AttendanceLog $log): array => [
                $log->team?->team_name ?? '',
                $log->user?->name ?? '',
            ])
            ->values()
            ->map(fn (AttendanceLog $log): array => [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'user_name' => $log->user?->name,
                'team_id' => $log->team_id,
                'team_name' => $log->team?->team_name,
                'date' => $log->date->toDateString(),
                'submitted_code' => $log->submitted_code
                    ?? $log->leaveType?->leave_type_code
                    ?? 'O',
                'leave_type_code' => $log->leaveType?->leave_type_code,
                'leave_type_name' => $log->leaveType?->name,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Daily report generated successfully.',
            'data' => [
                'date' => Carbon::parse($date)->toDateString(),
                'records' => $logs,
            ],
        ], 200);
    }

    /**
     * Monthly report — user × day-of-month attendance matrix.
     *
     * PRD: Matrix of users vs days (1–31) with row totals for Annual, Sick, Other.
     * Eager loads team + filtered attendanceLogs.leaveType per user in two query batches.
     *
     * Query: ?year=2026&month=6
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "data": {
     *     "year": 2026,
     *     "month": 6,
     *     "rows": [
     *       {
     *         "user_id": 5,
     *         "user_name": "Jane Doe",
     *         "team_name": "Engineering",
     *         "days": { "1": "A", "2": "W", "15": null },
     *         "totals": { "annual": 3, "sick": 1, "other": 2 }
     *       }
     *     ]
     *   }
     * }
     */
    public function monthly(MonthlyReportRequest $request): JsonResponse
    {
        $year = (int) $request->validated('year');
        $month = (int) $request->validated('month');

        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $daysInMonth = $periodStart->daysInMonth;

        // Eager-load team + month-scoped attendance logs with leave types (N+1 safe).
        $users = User::query()
            ->with([
                'team',
                'attendanceLogs' => fn ($query) => $query
                    ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                    ->with('leaveType'),
            ])
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (User $user): array => [
                $user->team?->team_name ?? '',
                $user->name,
            ])
            ->values();

        $rows = $users->map(function (User $user) use ($daysInMonth): array {
            // Initialize empty day slots 1..N for spreadsheet-style matrix columns.
            /** @var array<int, string|null> $dayMatrix */
            $dayMatrix = array_fill(1, $daysInMonth, null);

            $annualTotal = 0;
            $sickTotal = 0;
            $otherTotal = 0;

            foreach ($user->attendanceLogs as $log) {
                $dayOfMonth = (int) $log->date->format('j');
                $code = $log->leaveType?->leave_type_code;

                // Populate matrix cell with leave code (null = non-leave W/O/X or no entry).
                $dayMatrix[$dayOfMonth] = $code;

                // Row-end totals — classify by leave_type_code (PRD: Annual, Sick, Other).
                if ($code === 'A') {
                    $annualTotal++;
                } elseif ($code === 'S') {
                    $sickTotal++;
                } elseif ($code !== null) {
                    $otherTotal++;
                }
            }

            return [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'team_name' => $user->team?->team_name,
                'days' => $dayMatrix,
                'totals' => [
                    'annual' => $annualTotal,
                    'sick' => $sickTotal,
                    'other' => $otherTotal,
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Monthly report generated successfully.',
            'data' => [
                'year' => $year,
                'month' => $month,
                'days_in_month' => $daysInMonth,
                'rows' => $rows,
            ],
        ], 200);
    }

    /**
     * Yearly report — flattened leave balance pivot per user (Excel-style).
     *
     * Queries normalized vertical rows from user_yearly_leave_records, eager loads
     * leaveType + user.team, then transforms via transformYearlyPivot().
     *
     * Query: ?year=2026
     */
    public function yearly(YearlyReportRequest $request): JsonResponse
    {
        $year = (int) $request->validated('year');

        // Load all vertical balance rows for the year with relationships — single eager batch.
        $records = UserYearlyLeaveRecord::query()
            ->with(['leaveType', 'user.team'])
            ->where('year', $year)
            ->get();

        // Master column catalog — ensures consistent keys even when a user lacks a leave type row.
        $leaveTypes = LeaveType::query()
            ->orderBy('leave_type_code')
            ->get();

        $pivotRows = $this->transformYearlyPivot($records, $leaveTypes);

        return response()->json([
            'success' => true,
            'message' => 'Yearly report generated successfully.',
            'data' => [
                'year' => $year,
                'leave_type_columns' => $leaveTypes->pluck('leave_type_code')->all(),
                'rows' => $pivotRows,
            ],
        ], 200);
    }

    /**
     * Transform normalized vertical DB rows into horizontal spreadsheet-style JSON per user.
     *
     * ─── DATABASE SHAPE (vertical / normalized) ───────────────────────────────
     * user_yearly_leave_records stores ONE ROW per (user, leave_type, year):
     *
     *   | user_id | leave_type_id | year | assigned_days | taken_days |
     *   |    5    |       1 (A)   | 2026 |     14.50     |    2.00    |
     *   |    5    |       2 (S)   | 2026 |     10.00     |    1.00    |
     *
     * ─── TARGET SHAPE (horizontal / flattened pivot) ──────────────────────────
     * Legacy Excel sheets expect ONE OBJECT per user with dynamic columns:
     *
     *   {
     *     "user_id": 5,
     *     "user_name": "Jane Doe",
     *     "team_name": "Engineering",
     *     "A_assigned": 14.5,
     *     "A_taken": 2.0,
     *     "A_remaining": 12.5,
     *     "S_assigned": 10.0,
     *     "S_taken": 1.0,
     *     "S_remaining": 9.0,
     *     "annual_leave_remaining": 12.5,
     *     "total_absences": 3.0
     *   }
     *
     * ─── TRANSFORMATION STEPS ─────────────────────────────────────────────────
     * 1. groupBy(user_id) — collapse vertical rows into per-user collections.
     * 2. For each leave type row, project leave_type_code into flat keys:
     *      {code}_assigned  ← assigned_days
     *      {code}_taken     ← taken_days
     *      {code}_remaining ← assigned_days − taken_days (runtime, never stored)
     * 3. annual_leave_remaining ← A_remaining specifically (HighLevelArchitecture.md).
     * 4. total_absences       ← Σ taken_days across ALL leave types for the user.
     *
     * @param  Collection<int, UserYearlyLeaveRecord>  $records
     * @param  Collection<int, LeaveType>  $leaveTypes
     * @return list<array<string, mixed>>
     */
    private function transformYearlyPivot(Collection $records, Collection $leaveTypes): array
    {
        /** @var list<array<string, mixed>> $pivotRows */
        $pivotRows = [];

        // Step 1: Group vertical rows by user — each group becomes one spreadsheet row.
        $groupedByUser = $records->groupBy('user_id');

        foreach ($groupedByUser as $userId => $userRecords) {
            /** @var Collection<int, UserYearlyLeaveRecord> $userRecords */
            $firstRecord = $userRecords->first();
            $user = $firstRecord?->user;

            // Base row metadata — identity columns preceding dynamic leave columns.
            $flatRow = [
                'user_id' => (int) $userId,
                'user_name' => $user?->name,
                'team_name' => $user?->team?->team_name,
            ];

            // Initialize all known leave type columns to zero for consistent spreadsheet width.
            foreach ($leaveTypes as $leaveType) {
                $code = $leaveType->leave_type_code;
                $flatRow["{$code}_assigned"] = 0.0;
                $flatRow["{$code}_taken"] = 0.0;
                $flatRow["{$code}_remaining"] = 0.0;
            }

            // Running sum for total_absences — accumulates taken_days across every leave category.
            $totalAbsences = 0.0;

            // Step 2: Project each vertical row into horizontal {code}_* columns.
            foreach ($userRecords as $record) {
                $code = $record->leaveType?->leave_type_code;

                if ($code === null) {
                    continue;
                }

                $assigned = (float) $record->assigned_days;
                $taken = (float) $record->taken_days;

                // Runtime remaining per leave type — mirrors UserYearlyLeaveRecord accessor math.
                $remaining = round($assigned - $taken, 2);

                $flatRow["{$code}_assigned"] = $assigned;
                $flatRow["{$code}_taken"] = $taken;
                $flatRow["{$code}_remaining"] = $remaining;

                // Step 4a: Accumulate total absences — sum of all taken_days (all leave categories).
                $totalAbsences = round($totalAbsences + $taken, 2);
            }

            // Step 3: Annual Leave Remaining — specifically the "A" category remaining column.
            // HighLevelArchitecture.md: Annual Leave Remaining = Assigned Annual − Taken Annual.
            $flatRow['annual_leave_remaining'] = $flatRow['A_remaining'] ?? 0.0;

            // Step 4b: Attach computed aggregate — never stored in DB.
            $flatRow['total_absences'] = $totalAbsences;

            $pivotRows[] = $flatRow;
        }

        // Sort pivot output by team then user name for Admin spreadsheet parity.
        usort($pivotRows, function (array $a, array $b): int {
            $teamCompare = strcmp((string) ($a['team_name'] ?? ''), (string) ($b['team_name'] ?? ''));

            return $teamCompare !== 0
                ? $teamCompare
                : strcmp((string) ($a['user_name'] ?? ''), (string) ($b['user_name'] ?? ''));
        });

        return $pivotRows;
    }
}
