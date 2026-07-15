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
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Admin pivot reporting engine — daily, monthly, and yearly aggregation APIs.
 *
 * Secured at the route layer via auth:sanctum + role:Admin middleware.
 * All endpoints strictly eager-load relationships via with() to prevent N+1 queries.
 *
 * Roster-first: Yearly / Monthly / Daily start from User::query() (optionally filtered
 * by team_id). Leave and attendance data are appended; users are never dropped solely
 * because they lack leave rows or attendance logs for the selected period.
 */
class ReportController extends Controller
{
    /**
     * Daily report — attendance entries for a single calendar date.
     *
     * Roster-first: every active user (optionally scoped by team) appears.
     * Attendance is merged when a log exists; otherwise codes remain null.
     *
     * Query: ?date=2026-06-26&team_id=2
     */
    public function daily(DailyReportRequest $request): JsonResponse
    {
        $date = $request->validated('date');
        $teamId = $request->validated('team_id');
        $resolvedDate = Carbon::parse($date)->toDateString();

        $usersQuery = User::query()
            ->with('team')
            ->where('is_active', true);

        if ($teamId !== null && $teamId !== '') {
            $usersQuery->where('team_id', $teamId);
        }

        $users = $usersQuery
            ->get()
            ->sortBy(fn (User $user): array => [
                $user->team?->team_name ?? '',
                $user->name,
            ])
            ->values();

        $logsQuery = AttendanceLog::query()
            ->with([
                'leaveType' => fn ($query) => $query->withTrashed(),
            ])
            ->whereDate('date', $resolvedDate);

        if ($teamId !== null && $teamId !== '') {
            $logsQuery->where('team_id', $teamId);
        }

        /** @var Collection<int, AttendanceLog> $logsByUserId */
        $logsByUserId = $logsQuery->get()->keyBy('user_id');

        $records = $users->map(function (User $user) use ($logsByUserId, $resolvedDate): array {
            /** @var AttendanceLog|null $log */
            $log = $logsByUserId->get($user->id);

            if ($log === null) {
                return [
                    'id' => null,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'team_id' => $user->team_id,
                    'team_name' => $user->team?->team_name,
                    'date' => $resolvedDate,
                    'submitted_code' => null,
                    'leave_type_code' => null,
                    'leave_type_name' => null,
                ];
            }

            return [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'user_name' => $user->name,
                'team_id' => $log->team_id,
                'team_name' => $user->team?->team_name,
                'date' => $log->date->toDateString(),
                'submitted_code' => $log->submitted_code
                    ?? $log->leaveType?->leave_type_code
                    ?? 'O',
                'leave_type_code' => $log->leaveType?->leave_type_code,
                'leave_type_name' => $log->leaveType?->name,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Daily report generated successfully.',
            'data' => [
                'date' => $resolvedDate,
                'records' => $records,
            ],
        ], 200);
    }

    /**
     * Monthly report — user × day-of-month attendance matrix.
     *
     * Roster-first: every active user (optionally filtered by team) appears as a row.
     * Month-scoped attendanceLogs are eager-loaded; empty logs yield zero totals / blank days.
     *
     * Query: ?year=2026&month=6&team_id=2
     */
    public function monthly(MonthlyReportRequest $request): JsonResponse
    {
        $year = (int) $request->validated('year');
        $month = (int) $request->validated('month');
        $teamId = $request->validated('team_id');

        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $daysInMonth = $periodStart->daysInMonth;

        $usersQuery = User::query()
            ->with([
                'team',
                'attendanceLogs' => fn ($query) => $query
                    ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                    ->with(['leaveType' => fn ($leaveTypeQuery) => $leaveTypeQuery->withTrashed()]),
            ])
            ->where('is_active', true);

        if ($teamId !== null && $teamId !== '') {
            $usersQuery->where('team_id', $teamId);
        }

        $users = $usersQuery
            ->get()
            ->sortBy(fn (User $user): array => [
                $user->team?->team_name ?? '',
                $user->name,
            ])
            ->values();

        $rows = $users->map(function (User $user) use ($daysInMonth): array {
            /** @var array<int, string|null> $dayMatrix */
            $dayMatrix = array_fill(1, $daysInMonth, null);

            $annualTotal = 0;
            $sickTotal = 0;
            $otherTotal = 0;
            $totalWorkInOffice = 0;
            $totalWfh = 0;

            foreach ($user->attendanceLogs as $log) {
                $dayOfMonth = (int) $log->date->format('j');
                $attendanceCode = $this->resolveAttendanceCode($log);
                $leaveCode = $log->leaveType?->leave_type_code;

                $dayMatrix[$dayOfMonth] = $leaveCode ?? ($attendanceCode !== '' ? $attendanceCode : null);

                if ($leaveCode === 'A') {
                    $annualTotal++;
                } elseif ($leaveCode === 'S') {
                    $sickTotal++;
                } elseif ($leaveCode !== null) {
                    $otherTotal++;
                }

                if ($attendanceCode === 'O') {
                    $totalWorkInOffice++;
                } elseif ($attendanceCode === 'W') {
                    $totalWfh++;
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
                'total_work_in_office' => $totalWorkInOffice,
                'total_wfh' => $totalWfh,
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
     * Roster-first: every active user (optionally filtered by team) appears as a row.
     * Year-scoped leave balances are eager-loaded and default to 0.0 when absent —
     * users are never dropped simply because they lack leave allocation rows.
     *
     * Query: ?year=2026&team_id=2
     */
    public function yearly(YearlyReportRequest $request): JsonResponse
    {
        $year = (int) $request->validated('year');
        $teamId = $request->validated('team_id');

        $leaveTypes = LeaveType::query()
            ->withTrashed()
            ->orderBy('leave_type_code')
            ->get();

        $usersQuery = User::query()
            ->with([
                'team',
                'yearlyLeaveRecords' => static function ($query) use ($year): void {
                    $query
                        ->where('year', $year)
                        ->with(['leaveType' => static fn ($leaveTypeQuery) => $leaveTypeQuery->withTrashed()]);
                },
            ])
            ->where('is_active', true);

        if ($teamId !== null && $teamId !== '') {
            $usersQuery->where('team_id', $teamId);
        }

        $users = $usersQuery
            ->orderBy('name')
            ->get();

        $workDayTotalsByUser = $this->countWorkDayTotalsByUserForYear(
            $year,
            $teamId !== null && $teamId !== '' ? (int) $teamId : null,
        );

        $pivotRows = $this->transformYearlyPivot($users, $leaveTypes, $workDayTotalsByUser);

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
     * Build horizontal spreadsheet-style yearly pivot rows from a user roster.
     *
     * Iterates users (not leave-record groups) so members without allocations still
     * appear with 0.0 Assigned / Taken / Remaining for every leave type column.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, LeaveType>  $leaveTypes
     * @param  array<int, array{total_work_in_office: int, total_wfh: int}>  $workDayTotalsByUser
     * @return list<array<string, mixed>>
     */
    private function transformYearlyPivot(
        Collection $users,
        Collection $leaveTypes,
        array $workDayTotalsByUser = [],
    ): array {
        /** @var list<array<string, mixed>> $pivotRows */
        $pivotRows = [];

        foreach ($users as $user) {
            $flatRow = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'team_name' => $user->team?->team_name,
            ];

            foreach ($leaveTypes as $leaveType) {
                $code = $leaveType->leave_type_code;
                $flatRow["{$code}_assigned"] = 0.0;
                $flatRow["{$code}_taken"] = 0.0;
                $flatRow["{$code}_remaining"] = 0.0;
            }

            $totalAbsences = 0.0;

            foreach ($user->yearlyLeaveRecords as $record) {
                $code = $record->leaveType?->leave_type_code;

                if ($code === null) {
                    continue;
                }

                $assigned = (float) $record->assigned_days;
                $taken = (float) $record->taken_days;
                $remaining = round($assigned - $taken, 2);

                $flatRow["{$code}_assigned"] = $assigned;
                $flatRow["{$code}_taken"] = $taken;
                $flatRow["{$code}_remaining"] = $remaining;

                $totalAbsences = round($totalAbsences + $taken, 2);
            }

            $flatRow['annual_leave_remaining'] = $flatRow['A_remaining'] ?? 0.0;
            $flatRow['total_absences'] = $totalAbsences;

            $workDayTotals = $workDayTotalsByUser[$user->id] ?? [
                'total_work_in_office' => 0,
                'total_wfh' => 0,
            ];
            $flatRow['total_work_in_office'] = $workDayTotals['total_work_in_office'];
            $flatRow['total_wfh'] = $workDayTotals['total_wfh'];

            $pivotRows[] = $flatRow;
        }

        usort($pivotRows, function (array $a, array $b): int {
            $teamCompare = strcmp((string) ($a['team_name'] ?? ''), (string) ($b['team_name'] ?? ''));

            return $teamCompare !== 0
                ? $teamCompare
                : strcmp((string) ($a['user_name'] ?? ''), (string) ($b['user_name'] ?? ''));
        });

        return $pivotRows;
    }

    /**
     * Count Work-in-Office (O) and Work-from-Home (W) attendance days per user for a year.
     *
     * @param  int|null  $teamId  When set, only count logs for users currently on this team.
     * @return array<int, array{total_work_in_office: int, total_wfh: int}>
     */
    private function countWorkDayTotalsByUserForYear(int $year, ?int $teamId = null): array
    {
        $logsQuery = AttendanceLog::query()
            ->with(['leaveType' => fn ($query) => $query->withTrashed()])
            ->whereYear('date', $year);

        if ($teamId !== null) {
            $logsQuery->whereHas(
                'user',
                static fn ($query) => $query->where('team_id', $teamId),
            );
        }

        /** @var Collection<int, AttendanceLog> $logs */
        $logs = $logsQuery->get(['id', 'user_id', 'submitted_code', 'leave_type_id']);

        /** @var array<int, array{total_work_in_office: int, total_wfh: int}> $totalsByUser */
        $totalsByUser = [];

        foreach ($logs as $log) {
            $userId = (int) $log->user_id;

            if (! array_key_exists($userId, $totalsByUser)) {
                $totalsByUser[$userId] = [
                    'total_work_in_office' => 0,
                    'total_wfh' => 0,
                ];
            }

            $code = $this->resolveAttendanceCode($log);

            if ($code === 'O') {
                $totalsByUser[$userId]['total_work_in_office']++;
            } elseif ($code === 'W') {
                $totalsByUser[$userId]['total_wfh']++;
            }
        }

        return $totalsByUser;
    }

    /**
     * Resolve the effective attendance code for reporting.
     */
    private function resolveAttendanceCode(AttendanceLog $log): string
    {
        if ($log->submitted_code !== null && $log->submitted_code !== '') {
            return strtoupper($log->submitted_code);
        }

        $leaveTypeCode = $log->leaveType?->leave_type_code;

        return $leaveTypeCode !== null ? strtoupper($leaveTypeCode) : '';
    }
}
