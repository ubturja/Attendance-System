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
 * Team filters prefer attendance_logs.team_id (snapshotted at log time) over the
 * user's current users.team_id so transfers do not bleed days across team reports.
 * Roster rows may still include current members; displayed codes/totals only count
 * logs whose team_id matches the requested team.
 */
class ReportController extends Controller
{
    /**
     * Daily report — attendance entries for a single calendar date.
     *
     * Roster-first: every active user (optionally scoped by team) appears.
     * Attendance is merged when a log exists; otherwise codes remain null.
     * Includes Admin audit fields (updated_by / updated_at / editor) when present.
     *
     * Query: ?date=2026-06-26&team_id=2  (date defaults to today)
     */
    public function daily(DailyReportRequest $request): JsonResponse
    {
        $dateInput = $request->validated('date');
        $teamId = $request->validated('team_id');
        $resolvedDate = $dateInput !== null && $dateInput !== ''
            ? Carbon::parse($dateInput)->toDateString()
            : now()->toDateString();

        $usersQuery = User::query()
            ->where('is_active', true)
            ->with([
                'team',
                // Date-scoped attendance for this report day (0–1 row per user).
                // When team_id is set, only the snapshotted team row is loaded.
                'attendanceLogs' => static function ($query) use ($resolvedDate, $teamId): void {
                    $query
                        ->whereDate('date', $resolvedDate)
                        ->with([
                            'leaveType' => fn ($leaveQuery) => $leaveQuery->withTrashed(),
                            'team' => fn ($teamQuery) => $teamQuery->withTrashed(),
                            'editor',
                        ]);

                    if ($teamId !== null && $teamId !== '') {
                        $query->where('team_id', $teamId);
                    }
                },
            ]);

        if ($teamId !== null && $teamId !== '') {
            $usersQuery->where(function ($query) use ($teamId, $resolvedDate): void {
                $query
                    ->where('team_id', $teamId)
                    ->orWhereHas('attendanceLogs', static function ($attendanceQuery) use ($teamId, $resolvedDate): void {
                        $attendanceQuery
                            ->where('team_id', $teamId)
                            ->whereDate('date', $resolvedDate);
                    });
            });
        }

        $teamIdForDisplay = $teamId !== null && $teamId !== '' ? (int) $teamId : null;

        $users = $usersQuery
            ->get()
            ->sortBy(fn (User $user): array => [
                $this->historicalTeamName($user, $teamIdForDisplay) ?? '',
                $user->name,
            ])
            ->values();

        $records = $users->map(function (User $user) use ($resolvedDate, $teamIdForDisplay): array {
            /** @var AttendanceLog|null $log */
            $log = $user->attendanceLogs->first();
            $teamName = $this->historicalTeamName($user, $teamIdForDisplay);

            if ($log === null) {
                return [
                    'id' => null,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'team_id' => $user->team_id,
                    'team_name' => $teamName,
                    'date' => $resolvedDate,
                    'submitted_code' => null,
                    'leave_type_code' => null,
                    'leave_type_name' => null,
                    'updated_by' => null,
                    'updated_at' => null,
                    'updated_by_name' => null,
                ];
            }

            return [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'user_name' => $user->name,
                'team_id' => $log->team_id,
                'team_name' => $teamName,
                'date' => $log->date->toDateString(),
                'submitted_code' => $log->submitted_code
                    ?? $log->leaveType?->leave_type_code
                    ?? 'O',
                'leave_type_code' => $log->leaveType?->leave_type_code,
                'leave_type_name' => $log->leaveType?->name,
                'updated_by' => $log->updated_by,
                'updated_at' => $log->updated_at?->toIso8601String(),
                'updated_by_name' => $log->editor?->name,
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
     * Monthly report — user × day-of-month attendance matrix for a calendar grid.
     *
     * Roster-first: every user including soft-deleted/archived (optionally filtered by
     * team) appears as a row so historical payroll audits stay complete.
     * Month-scoped attendanceLogs are eager-loaded into `daily_records` (day → code);
     * empty logs yield zero totals / blank days.
     *
     * Aggregate totals (office, WFH, leave counts) exclude Saturdays and Sundays
     * and use fractional math for half-day variants (AO/OA → 0.5 leave + 0.5 office).
     * Calendar cells expose the raw submitted_code (not the parent leave type).
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
            ->withTrashed()
            ->with([
                'team',
                // Month-scoped logs. Team filters bind to attendance_logs.team_id so
                // days logged on another team never appear in this team's matrix.
                'attendanceLogs' => static function ($query) use ($year, $month, $teamId): void {
                    $query
                        ->whereYear('date', $year)
                        ->whereMonth('date', $month)
                        ->orderBy('date')
                        ->with([
                            'leaveType' => static fn ($leaveTypeQuery) => $leaveTypeQuery->withTrashed(),
                            'team' => static fn ($teamQuery) => $teamQuery->withTrashed(),
                        ]);

                    if ($teamId !== null && $teamId !== '') {
                        $query->where('team_id', $teamId);
                    }
                },
            ]);

        if ($teamId !== null && $teamId !== '') {
            $usersQuery->where(function ($query) use ($teamId, $periodStart, $periodEnd): void {
                $query
                    ->where('team_id', $teamId)
                    ->orWhereHas('attendanceLogs', static function ($attendanceQuery) use ($teamId, $periodStart, $periodEnd): void {
                        $attendanceQuery
                            ->where('team_id', $teamId)
                            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()]);
                    });
            });
        }

        $teamIdForDisplay = $teamId !== null && $teamId !== '' ? (int) $teamId : null;

        $users = $usersQuery
            ->get()
            ->sortBy(fn (User $user): array => [
                $this->historicalTeamName($user, $teamIdForDisplay) ?? '',
                $user->name,
            ])
            ->values();

        $rows = $users->map(function (User $user) use ($daysInMonth, $teamIdForDisplay): array {
            /** @var array<int, string|null> $dailyRecords Day-of-month → attendance/leave code. */
            $dailyRecords = array_fill(1, $daysInMonth, null);

            $annualTotal = 0.0;
            $sickTotal = 0.0;
            $otherTotal = 0.0;
            $totalWorkInOffice = 0.0;
            $totalWfh = 0.0;

            foreach ($user->attendanceLogs as $log) {
                $dayOfMonth = (int) $log->date->format('j');
                // Prefer submitted_code (AO/OA/…) so the calendar shows what was logged.
                $submittedCode = $this->resolveAttendanceCode($log);

                $dailyRecords[$dayOfMonth] = $submittedCode !== '' ? $submittedCode : null;

                // Aggregates count working days only — Sat/Sun are excluded.
                if ($log->date->isWeekend()) {
                    continue;
                }

                $fractions = $this->monthlyDayFractions($submittedCode);

                $annualTotal = round($annualTotal + $fractions['annual'], 2);
                $sickTotal = round($sickTotal + $fractions['sick'], 2);
                $otherTotal = round($otherTotal + $fractions['other'], 2);
                $totalWorkInOffice = round($totalWorkInOffice + $fractions['office'], 2);
                $totalWfh = round($totalWfh + $fractions['wfh'], 2);
            }

            return [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'team_name' => $this->historicalTeamName($user, $teamIdForDisplay),
                'daily_records' => $dailyRecords,
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
     * Roster-first: every user including soft-deleted/archived (optionally filtered by
     * team) appears as a row so historical payroll audits stay complete.
     * Year-scoped leave balances are eager-loaded and default to 0.0 when absent —
     * users are never dropped simply because they lack leave allocation rows.
     *
     * Query: ?year=2026&team_id=2
     */
    public function yearly(YearlyReportRequest $request): JsonResponse
    {
        $year = (int) $request->validated('year');
        $teamId = $request->validated('team_id');
        $periodStart = Carbon::create($year, 1, 1)->startOfDay();
        $periodEnd = Carbon::create($year, 12, 31)->endOfDay();

        $leaveTypes = LeaveType::query()
            ->withTrashed()
            ->orderBy('leave_type_code')
            ->get();

        $usersQuery = User::query()
            ->withTrashed()
            ->with([
                'team',
                'yearlyLeaveRecords' => static function ($query) use ($year): void {
                    $query
                        ->where('year', $year)
                        ->with(['leaveType' => static fn ($leaveTypeQuery) => $leaveTypeQuery->withTrashed()]);
                },
                // Year-scoped logs. Team filters bind to attendance_logs.team_id so
                // O/W totals (and historical team_name) stay with the snapshotted team.
                'attendanceLogs' => static function ($query) use ($year, $teamId): void {
                    $query
                        ->whereYear('date', $year)
                        ->orderBy('date')
                        ->with([
                            'leaveType' => static fn ($leaveTypeQuery) => $leaveTypeQuery->withTrashed(),
                            'team' => static fn ($teamQuery) => $teamQuery->withTrashed(),
                        ]);

                    if ($teamId !== null && $teamId !== '') {
                        $query->where('team_id', $teamId);
                    }
                },
            ]);

        if ($teamId !== null && $teamId !== '') {
            $usersQuery->where(function ($query) use ($teamId, $periodStart, $periodEnd): void {
                $query
                    ->where('team_id', $teamId)
                    ->orWhereHas('attendanceLogs', static function ($attendanceQuery) use ($teamId, $periodStart, $periodEnd): void {
                        $attendanceQuery
                            ->where('team_id', $teamId)
                            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()]);
                    });
            });
        }

        $teamIdForDisplay = $teamId !== null && $teamId !== '' ? (int) $teamId : null;

        $users = $usersQuery
            ->get()
            ->sortBy(fn (User $user): array => [
                $this->historicalTeamName($user, $teamIdForDisplay) ?? '',
                $user->name,
            ])
            ->values();

        $workDayTotalsByUser = $this->countWorkDayTotalsByUserForYear(
            $year,
            $teamId !== null && $teamId !== '' ? (int) $teamId : null,
        );

        $pivotRows = $this->transformYearlyPivot(
            $users,
            $leaveTypes,
            $workDayTotalsByUser,
            $teamIdForDisplay,
        );

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
     * @param  array<int, array{total_work_in_office: float, total_wfh: float}>  $workDayTotalsByUser
     * @return list<array<string, mixed>>
     */
    private function transformYearlyPivot(
        Collection $users,
        Collection $leaveTypes,
        array $workDayTotalsByUser = [],
        ?int $teamId = null,
    ): array {
        /** @var list<array<string, mixed>> $pivotRows */
        $pivotRows = [];

        foreach ($users as $user) {
            $flatRow = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'team_name' => $this->historicalTeamName($user, $teamId),
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
                // Ledger totals (taken_days) come from attendance submit/admin override.
                // Write-path weekend guard charges 0.0 on Sat/Sun, so ledger absences
                // already exclude weekends when leave was logged through attendance APIs.
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
                'total_work_in_office' => 0.0,
                'total_wfh' => 0.0,
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
     * Saturdays and Sundays are excluded so totals reflect working days only.
     * Uses the same fractional half-day math as the monthly report (via
     * monthlyDayFractions), so half-day variants like AO/OA/SO/OS add 0.5 to
     * the office total instead of being skipped.
     *
     * @param  int|null  $teamId  When set, only count logs snapshotted to this team.
     * @return array<int, array{total_work_in_office: float, total_wfh: float}>
     */
    private function countWorkDayTotalsByUserForYear(int $year, ?int $teamId = null): array
    {
        $logsQuery = AttendanceLog::query()
            ->with(['leaveType' => fn ($query) => $query->withTrashed()])
            ->whereYear('date', $year);

        if ($teamId !== null) {
            $logsQuery->where('team_id', $teamId);
        }

        /** @var Collection<int, AttendanceLog> $logs */
        $logs = $logsQuery->get(['id', 'user_id', 'date', 'submitted_code', 'leave_type_id']);

        /** @var array<int, array{total_work_in_office: float, total_wfh: float}> $totalsByUser */
        $totalsByUser = [];

        foreach ($logs as $log) {
            if ($log->date->isWeekend()) {
                continue;
            }

            $userId = (int) $log->user_id;

            if (! array_key_exists($userId, $totalsByUser)) {
                $totalsByUser[$userId] = [
                    'total_work_in_office' => 0.0,
                    'total_wfh' => 0.0,
                ];
            }

            $fractions = $this->monthlyDayFractions($this->resolveAttendanceCode($log));

            $totalsByUser[$userId]['total_work_in_office'] = round(
                $totalsByUser[$userId]['total_work_in_office'] + $fractions['office'],
                2,
            );
            $totalsByUser[$userId]['total_wfh'] = round(
                $totalsByUser[$userId]['total_wfh'] + $fractions['wfh'],
                2,
            );
        }

        return $totalsByUser;
    }

    /**
     * Resolve display team from attendance log snapshots before falling back to current membership.
     */
    private function historicalTeamName(User $user, ?int $preferredTeamId = null): ?string
    {
        /** @var AttendanceLog|null $preferredLog */
        $preferredLog = $preferredTeamId !== null
            ? $user->attendanceLogs->first(
                static fn (AttendanceLog $log): bool => (int) $log->team_id === $preferredTeamId,
            )
            : null;

        /** @var AttendanceLog|null $log */
        $log = $preferredLog ?? $user->attendanceLogs->first();

        return $log?->team?->team_name ?? $user->team?->team_name;
    }

    /**
     * Split a submitted attendance code into monthly report day fractions.
     *
     * Half-day variants (AO/OA/NO/ON/SO/OS) contribute 0.5 to leave and 0.5 to
     * the paired work code. Full-day codes contribute 1.0 to a single bucket.
     *
     * @return array{annual: float, sick: float, other: float, office: float, wfh: float}
     */
    private function monthlyDayFractions(string $submittedCode): array
    {
        $fractions = [
            'annual' => 0.0,
            'sick' => 0.0,
            'other' => 0.0,
            'office' => 0.0,
            'wfh' => 0.0,
        ];

        $code = strtoupper(trim($submittedCode));

        if ($code === '') {
            return $fractions;
        }

        /** @var array<string, array{leave: string, work: string}> $halfDayVariants */
        $halfDayVariants = [
            'AO' => ['leave' => 'A', 'work' => 'O'],
            'OA' => ['leave' => 'A', 'work' => 'O'],
            'NO' => ['leave' => 'N', 'work' => 'O'],
            'ON' => ['leave' => 'N', 'work' => 'O'],
            'SO' => ['leave' => 'S', 'work' => 'O'],
            'OS' => ['leave' => 'S', 'work' => 'O'],
        ];

        if (array_key_exists($code, $halfDayVariants)) {
            $this->addLeaveFraction($fractions, $halfDayVariants[$code]['leave'], 0.5);
            $this->addWorkFraction($fractions, $halfDayVariants[$code]['work'], 0.5);

            return $fractions;
        }

        if ($code === 'O') {
            $fractions['office'] = 1.0;

            return $fractions;
        }

        if ($code === 'W') {
            $fractions['wfh'] = 1.0;

            return $fractions;
        }

        // X / OFF and unknown non-leave codes do not contribute to leave or work totals.
        if ($code === 'X') {
            return $fractions;
        }

        $this->addLeaveFraction($fractions, $code, 1.0);

        return $fractions;
    }

    /**
     * @param  array{annual: float, sick: float, other: float, office: float, wfh: float}  $fractions
     */
    private function addLeaveFraction(array &$fractions, string $leaveCode, float $amount): void
    {
        if ($leaveCode === 'A') {
            $fractions['annual'] = round($fractions['annual'] + $amount, 2);

            return;
        }

        if ($leaveCode === 'S') {
            $fractions['sick'] = round($fractions['sick'] + $amount, 2);

            return;
        }

        $fractions['other'] = round($fractions['other'] + $amount, 2);
    }

    /**
     * @param  array{annual: float, sick: float, other: float, office: float, wfh: float}  $fractions
     */
    private function addWorkFraction(array &$fractions, string $workCode, float $amount): void
    {
        if ($workCode === 'O') {
            $fractions['office'] = round($fractions['office'] + $amount, 2);

            return;
        }

        if ($workCode === 'W') {
            $fractions['wfh'] = round($fractions['wfh'] + $amount, 2);
        }
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
