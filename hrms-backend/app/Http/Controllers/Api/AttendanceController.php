<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminDailyAttendanceUpdateRequest;
use App\Http\Requests\StoreAttendanceRequest;
use App\Http\Requests\UpdateAttendanceRequest;
use App\Models\AttendanceLog;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use App\Services\Attendance\AttendanceValidationException;
use App\Services\Attendance\AttendanceVariantMapper;
use App\Services\Attendance\MappedAttendanceCode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Core attendance processing controller — fractional deduction and variant mapping.
 *
 * Accepts bulk attendance submissions inside a single DB::transaction().
 * If ANY record fails validation, the entire batch rolls back (all-or-nothing).
 *
 * Authorization (info.md):
 *   - Route: auth:sanctum + role:Admin,Employee (personal dashboard submission).
 *   - Employee: may only submit for users sharing the same team_id.
 *   - Admin: unrestricted team scope (including own attendance when assigned to a team).
 *   - Identity / actor always from $request->user() — never from a role-hardcoded ID.
 *
 * HighLevelArchitecture.md workflow executed per record:
 *   1. Map frontend code (AO/OA→A 0.5, NO/ON→N 0.5, W/O/X→NULL bypass validation, standard→1.0).
 *   2. Reject duplicate (user_id, date) rows — one log per employee per day.
 *   3. Validate remaining_days will not drop below zero after cumulative deductions.
 *   4. Increment taken_days on user_yearly_leave_records (0.0 when the date is a weekend).
 *   5. Insert attendance_logs row with resolved leave_type_id.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceVariantMapper $variantMapper,
    ) {}

    /**
     * Process a bulk array of attendance records atomically.
     *
     * JSON response (201):
     * {
     *   "success": true,
     *   "message": "Attendance records saved successfully.",
     *   "data": [ { "id": 1, "user_id": 5, "team_id": 2, "date": "2026-06-26", "leave_type_id": 1 } ]
     * }
     *
     * JSON response (403) — employee team-scope violation:
     * {
     *   "success": false,
     *   "message": "Forbidden. You may only submit attendance for members of your assigned team.",
     *   "errors": { "user_id": 9 }
     * }
     *
     * JSON response (403) — non-today submission (dashboard is today-only):
     * {
     *   "success": false,
     *   "message": "You can only submit or update attendance for today. Contact your Admin for past changes."
     * }
     *
     * JSON response (422) — balance, duplicate, or mapping failure (entire batch rolled back):
     * {
     *   "success": false,
     *   "message": "Attendance already logged for this date.",
     *   "errors": { "user_id": 5, "date": "2026-06-26" }
     * }
     */
    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        /** @var list<array{user_id: int, date: string, code: string}> $records */
        $records = $request->validated('records');

        // Dashboard submissions are today-only. Past/future corrections go through Admin.
        foreach ($records as $record) {
            $submissionDate = $record['date'] ?? now()->toDateString();

            if (! Carbon::parse($submissionDate)->isToday()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only submit or update attendance for today. Contact your Admin for past changes.',
                ], 403);
            }
        }

        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();

        try {
            $this->assertEmployeeTeamScope($authenticatedUser, $records);

            // ── ATOMIC BATCH: all records succeed or none persist ─────────────────
            $createdLogs = DB::transaction(function () use ($records): array {
                $createdLogs = [];

                // In-memory accumulators for net balance math within this batch.
                /** @var array<string, float> $pendingDeductions */
                $pendingDeductions = [];
                /** @var array<string, float> $pendingRefunds */
                $pendingRefunds = [];

                // Prevents duplicate (user_id, date) rows within the same request payload.
                /** @var array<string, true> $seenAttendanceKeys */
                $seenAttendanceKeys = [];

                /** @var array<int, string> $leaveTypeCodesById */
                $leaveTypeCodesById = LeaveType::withTrashed()
                    ->pluck('leave_type_code', 'id')
                    ->all();

                // Pass 1: credit virtual refunds for code changes so later deductions
                // in the same batch see net-zero capacity (order-independent).
                $this->accumulatePendingRefunds(
                    records: $records,
                    pendingRefunds: $pendingRefunds,
                    leaveTypeCodesById: $leaveTypeCodesById,
                );

                foreach ($records as $record) {
                    $createdLogs[] = $this->processRecord(
                        record: $record,
                        pendingDeductions: $pendingDeductions,
                        pendingRefunds: $pendingRefunds,
                        leaveTypeCodesById: $leaveTypeCodesById,
                        seenAttendanceKeys: $seenAttendanceKeys,
                    );
                }

                return $createdLogs;
            });
        } catch (AttendanceValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->context,
            ], $exception->httpStatus);
        }

        return response()->json([
            'success' => true,
            'message' => 'Attendance records saved successfully.',
            'data' => $createdLogs,
        ], 201);
    }

    /**
     * Employees may only submit attendance for users on their own team (info.md).
     * Admins bypass this check entirely.
     *
     * @param  list<array{user_id: int, date: string, code: string}>  $records
     *
     * @throws AttendanceValidationException
     */
    private function assertEmployeeTeamScope(User $authenticatedUser, array $records): void
    {
        if ($authenticatedUser->job_title !== 'Employee') {
            return;
        }

        if ($authenticatedUser->team_id === null) {
            throw AttendanceValidationException::employeeMustBelongToTeam();
        }

        $submitterTeamId = (int) $authenticatedUser->team_id;

        /** @var list<int> $targetUserIds */
        $targetUserIds = array_values(array_unique(array_map(
            static fn (array $record): int => (int) $record['user_id'],
            $records,
        )));

        $targetsById = User::query()
            ->whereIn('id', $targetUserIds)
            ->get(['id', 'team_id'])
            ->keyBy('id');

        foreach ($targetUserIds as $targetUserId) {
            $targetUser = $targetsById->get($targetUserId);

            if ($targetUser === null || $targetUser->team_id === null) {
                throw AttendanceValidationException::forbiddenTeamScope($targetUserId);
            }

            if ((int) $targetUser->team_id !== $submitterTeamId) {
                throw AttendanceValidationException::forbiddenTeamScope($targetUserId);
            }
        }
    }

    /**
     * Process a single attendance row inside the open transaction.
     *
     * Creates a new log or upserts an existing (user_id, date) row when the code
     * changes — reversing the prior quota charge before validating the new one.
     *
     * @param  array{user_id: int, date: string, code: string}  $record
     * @param  array<string, float>  $pendingDeductions
     * @param  array<string, float>  $pendingRefunds
     * @param  array<int, string>  $leaveTypeCodesById
     * @param  array<string, true>  $seenAttendanceKeys
     *
     * @throws AttendanceValidationException
     */
    private function processRecord(
        array $record,
        array &$pendingDeductions,
        array &$pendingRefunds,
        array $leaveTypeCodesById,
        array &$seenAttendanceKeys,
    ): AttendanceLog {
        $userId = (int) $record['user_id'];
        $date = $record['date'];
        $newCode = strtoupper(trim($record['code']));

        $this->assertUniqueInBatch($userId, $date, $seenAttendanceKeys);

        $targetUser = User::query()->findOrFail($userId);

        if ($targetUser->team_id === null) {
            throw AttendanceValidationException::userMustBeAssignedToTeam($targetUser->id);
        }

        $year = (int) date('Y', strtotime($date));

        /** @var AttendanceLog|null $existing */
        $existing = AttendanceLog::query()
            ->where('user_id', $userId)
            ->whereDate('date', $date)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $this->upsertExistingRecord(
                existing: $existing,
                newCode: $newCode,
                userId: $userId,
                year: $year,
                date: $date,
                pendingDeductions: $pendingDeductions,
                pendingRefunds: $pendingRefunds,
                leaveTypeCodesById: $leaveTypeCodesById,
            );
        }

        $mapped = $this->variantMapper->map($newCode);

        if ($mapped->requiresBalanceCheck) {
            $this->validateAndReserveBalance(
                mapped: $mapped,
                userId: $userId,
                year: $year,
                date: $date,
                pendingDeductions: $pendingDeductions,
                pendingRefunds: $pendingRefunds,
                leaveTypeCodesById: $leaveTypeCodesById,
            );
        }

        // Snap the employee's team at log time so later transfers do not rewrite history.
        return AttendanceLog::query()->create([
            'user_id' => $targetUser->id,
            'team_id' => $targetUser->team_id,
            'date' => $date,
            'submitted_code' => $newCode,
            'leave_type_id' => $mapped->leaveTypeId,
        ]);
    }

    /**
     * Pre-scan the batch for quota refunds from code changes on existing logs.
     *
     * Credits are tracked in $pendingRefunds before any deduction validation so a
     * Tuesday A→O refund and a Wednesday O→A charge in the same payload net to zero
     * regardless of record order.
     *
     * @param  list<array{user_id: int, date: string, code: string}>  $records
     * @param  array<string, float>  $pendingRefunds
     * @param  array<int, string>  $leaveTypeCodesById
     */
    private function accumulatePendingRefunds(
        array $records,
        array &$pendingRefunds,
        array $leaveTypeCodesById,
    ): void {
        foreach ($records as $record) {
            $userId = (int) $record['user_id'];
            $date = $record['date'];
            $newCode = strtoupper(trim($record['code']));

            /** @var AttendanceLog|null $existing */
            $existing = AttendanceLog::query()
                ->where('user_id', $userId)
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                continue;
            }

            $oldCode = $this->resolveEffectiveCode($existing, $leaveTypeCodesById);

            if ($oldCode === $newCode) {
                continue;
            }

            $oldMapped = $this->variantMapper->map($oldCode);

            if (! $oldMapped->requiresBalanceCheck || $oldMapped->balanceLeaveTypeId === null) {
                continue;
            }

            $refundAmount = $this->ledgerDeductionForDate($oldMapped, $date);

            if ($refundAmount <= 0.0) {
                continue;
            }

            $year = (int) date('Y', strtotime($date));
            $balanceKey = $this->balanceKey($userId, $oldMapped->balanceLeaveTypeId, $year);
            $pendingRefunds[$balanceKey] = round(
                ($pendingRefunds[$balanceKey] ?? 0.0) + $refundAmount,
                2,
            );
        }
    }

    /**
     * Update an existing locked attendance row when the submitted code changes.
     *
     * @param  array<string, float>  $pendingDeductions
     * @param  array<string, float>  $pendingRefunds
     * @param  array<int, string>  $leaveTypeCodesById
     *
     * @throws AttendanceValidationException
     */
    private function upsertExistingRecord(
        AttendanceLog $existing,
        string $newCode,
        int $userId,
        int $year,
        string $date,
        array &$pendingDeductions,
        array &$pendingRefunds,
        array $leaveTypeCodesById,
    ): AttendanceLog {
        $oldCode = $this->resolveEffectiveCode($existing, $leaveTypeCodesById);

        if ($oldCode === $newCode) {
            return $existing;
        }

        $oldMapped = $this->variantMapper->map($oldCode);

        if ($oldMapped->requiresBalanceCheck) {
            $refundAmount = $this->ledgerDeductionForDate($oldMapped, $date);
            $this->releaseBalance($oldMapped, $userId, $year, $date);

            // Virtual pre-scan credit is now reflected in the ledger — consume it.
            if ($refundAmount > 0.0 && $oldMapped->balanceLeaveTypeId !== null) {
                $balanceKey = $this->balanceKey($userId, $oldMapped->balanceLeaveTypeId, $year);
                $pendingRefunds[$balanceKey] = round(
                    ($pendingRefunds[$balanceKey] ?? 0.0) - $refundAmount,
                    2,
                );
            }
        }

        $newMapped = $this->variantMapper->map($newCode);

        if ($newMapped->requiresBalanceCheck) {
            $this->validateAndReserveBalance(
                mapped: $newMapped,
                userId: $userId,
                year: $year,
                date: $date,
                pendingDeductions: $pendingDeductions,
                pendingRefunds: $pendingRefunds,
                leaveTypeCodesById: $leaveTypeCodesById,
            );
        }

        $existing->submitted_code = $newCode;
        $existing->leave_type_id = $newMapped->leaveTypeId;
        $existing->save();

        return $existing;
    }

    /**
     * Admin override — replace the attendance code on an existing log row.
     *
     * Reverses the prior balance deduction, validates the new code, and persists
     * the updated leave_type_id + submitted_code inside a single transaction.
     * Sets updated_by for Admin audit trail.
     */
    public function update(UpdateAttendanceRequest $request, AttendanceLog $attendanceLog): JsonResponse
    {
        $newCode = strtoupper(trim($request->validated('code')));

        /** @var User $admin */
        $admin = $request->user();

        try {
            $updatedLog = DB::transaction(function () use ($attendanceLog, $newCode, $admin): AttendanceLog {
                /** @var AttendanceLog $log */
                $log = AttendanceLog::query()
                    ->whereKey($attendanceLog->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->applyAdminCodeChange($log, $newCode, (int) $admin->id);
            });
        } catch (AttendanceValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->context,
            ], $exception->httpStatus);
        }

        return response()->json([
            'success' => true,
            'message' => 'Attendance record updated successfully.',
            'data' => $this->attendancePayload($updatedLog),
        ], 200);
    }

    /**
     * Admin daily-report upsert — create or update attendance for user + date.
     *
     * Body: user_id, date, code|status. Always stamps updated_by with the Admin id.
     */
    public function adminUpdate(AdminDailyAttendanceUpdateRequest $request): JsonResponse
    {
        $userId = (int) $request->validated('user_id');
        $date = Carbon::parse($request->validated('date'))->toDateString();
        $newCode = $request->attendanceCode();

        /** @var User $admin */
        $admin = $request->user();

        try {
            $updatedLog = DB::transaction(function () use ($userId, $date, $newCode, $admin): AttendanceLog {
                $targetUser = User::query()->findOrFail($userId);

                if ($targetUser->team_id === null) {
                    throw AttendanceValidationException::userMustBeAssignedToTeam($targetUser->id);
                }

                /** @var AttendanceLog|null $existing */
                $existing = AttendanceLog::query()
                    ->where('user_id', $userId)
                    ->whereDate('date', $date)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->applyAdminCodeChange($existing, $newCode, (int) $admin->id);
                }

                $mapped = $this->variantMapper->map($newCode);
                $leaveTypeCodesById = LeaveType::withTrashed()
                    ->pluck('leave_type_code', 'id')
                    ->all();
                $pendingDeductions = [];
                $pendingRefunds = [];
                $year = (int) date('Y', strtotime($date));

                if ($mapped->requiresBalanceCheck) {
                    $this->validateAndReserveBalance(
                        mapped: $mapped,
                        userId: $userId,
                        year: $year,
                        date: $date,
                        pendingDeductions: $pendingDeductions,
                        pendingRefunds: $pendingRefunds,
                        leaveTypeCodesById: $leaveTypeCodesById,
                    );
                }

                $log = AttendanceLog::query()->create([
                    'user_id' => $targetUser->id,
                    'team_id' => $targetUser->team_id,
                    'date' => $date,
                    'submitted_code' => $newCode,
                    'leave_type_id' => $mapped->leaveTypeId,
                    'updated_by' => $admin->id,
                ]);

                return $log->fresh(['leaveType', 'editor']);
            });
        } catch (AttendanceValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->context,
            ], $exception->httpStatus);
        }

        return response()->json([
            'success' => true,
            'message' => 'Attendance record saved successfully.',
            'data' => $this->attendancePayload($updatedLog),
        ], 200);
    }

    /**
     * Apply a new attendance code to a locked log row (balance reverse + reserve).
     *
     * @throws AttendanceValidationException
     */
    private function applyAdminCodeChange(
        AttendanceLog $log,
        string $newCode,
        int $adminUserId,
    ): AttendanceLog {
        /** @var array<int, string> $leaveTypeCodesById */
        $leaveTypeCodesById = LeaveType::withTrashed()
            ->pluck('leave_type_code', 'id')
            ->all();

        $oldCode = $this->resolveEffectiveCode($log, $leaveTypeCodesById);

        if ($oldCode !== $newCode) {
            $year = (int) $log->date->format('Y');
            $date = $log->date->toDateString();

            $oldMapped = $this->variantMapper->map($oldCode);
            if ($oldMapped->requiresBalanceCheck) {
                $this->releaseBalance($oldMapped, (int) $log->user_id, $year, $date);
            }

            $newMapped = $this->variantMapper->map($newCode);
            $pendingDeductions = [];
            $pendingRefunds = [];

            if ($newMapped->requiresBalanceCheck) {
                $this->validateAndReserveBalance(
                    mapped: $newMapped,
                    userId: (int) $log->user_id,
                    year: $year,
                    date: $date,
                    pendingDeductions: $pendingDeductions,
                    pendingRefunds: $pendingRefunds,
                    leaveTypeCodesById: $leaveTypeCodesById,
                );
            }

            $log->submitted_code = $newCode;
            $log->leave_type_id = $newMapped->leaveTypeId;
        }

        $log->updated_by = $adminUserId;
        $log->save();

        return $log->fresh(['leaveType', 'editor']);
    }

    /**
     * @return array<string, mixed>
     */
    private function attendancePayload(AttendanceLog $log): array
    {
        return [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'team_id' => $log->team_id,
            'date' => $log->date->toDateString(),
            'submitted_code' => $log->submitted_code,
            'leave_type_id' => $log->leave_type_id,
            'updated_by' => $log->updated_by,
            'updated_at' => $log->updated_at?->toIso8601String(),
            'updated_by_name' => $log->editor?->name,
        ];
    }

    /**
     * Resolve the code used for balance reversal when submitted_code is absent (legacy rows).
     *
     * @param  array<int, string>  $leaveTypeCodesById
     */
    private function resolveEffectiveCode(AttendanceLog $log, array $leaveTypeCodesById): string
    {
        if ($log->submitted_code !== null && $log->submitted_code !== '') {
            return strtoupper($log->submitted_code);
        }

        if ($log->leave_type_id !== null) {
            return strtoupper($leaveTypeCodesById[$log->leave_type_id] ?? 'O');
        }

        return 'O';
    }

    /**
     * Resolve the ledger charge for an attendance date.
     *
     * Weekends (Sat/Sun) always charge 0.0 so the attendance log can still be
     * stored for calendar visibility without draining leave balances.
     * Weekday charges preserve half-day (0.5) and full-day (1.0) amounts from the mapper.
     */
    private function ledgerDeductionForDate(MappedAttendanceCode $mapped, string $date): float
    {
        if (Carbon::parse($date)->isWeekend()) {
            return 0.0;
        }

        return $mapped->deductionAmount;
    }

    /**
     * Reverse a prior balance deduction when an Admin overrides an attendance code.
     *
     * Weekend logs were never charged, so reversal is a no-op for Sat/Sun.
     */
    private function releaseBalance(
        MappedAttendanceCode $mapped,
        int $userId,
        int $year,
        string $date,
    ): void {
        $deduction = $this->ledgerDeductionForDate($mapped, $date);

        if ($deduction <= 0.0) {
            return;
        }

        $balanceLeaveTypeId = $mapped->balanceLeaveTypeId;

        $balanceRecord = UserYearlyLeaveRecord::query()
            ->where('user_id', $userId)
            ->where('leave_type_id', $balanceLeaveTypeId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($balanceRecord === null) {
            return;
        }

        $balanceRecord->taken_days = max(
            0.0,
            round((float) $balanceRecord->taken_days - $deduction, 2),
        );
        $balanceRecord->save();
    }

    /**
     * Reject duplicate (user_id, date) pairs within a single request payload.
     * Existing DB rows for today are upserted — not rejected.
     *
     * @param  array<string, true>  $seenAttendanceKeys
     *
     * @throws AttendanceValidationException
     */
    private function assertUniqueInBatch(
        int $userId,
        string $date,
        array &$seenAttendanceKeys,
    ): void {
        $attendanceKey = "{$userId}_{$date}";

        if (isset($seenAttendanceKeys[$attendanceKey])) {
            throw AttendanceValidationException::attendanceAlreadyLogged($userId, $date);
        }

        $seenAttendanceKeys[$attendanceKey] = true;
    }

    private function balanceKey(int $userId, int $leaveTypeId, int $year): string
    {
        return "{$userId}_{$leaveTypeId}_{$year}";
    }

    /**
     * Validate remaining_days and increment taken_days on the parent balance row.
     *
     * Write-path weekend guard: Saturdays and Sundays charge 0.0 against the ledger
     * so leave codes remain visible on the calendar without consuming balance.
     *
     * Batch math credits $pendingRefunds (code changes that free quota later/earlier
     * in the same transaction) so net-zero swaps are not rejected.
     *
     * @param  array<string, float>  $pendingDeductions
     * @param  array<string, float>  $pendingRefunds
     * @param  array<int, string>  $leaveTypeCodesById
     *
     * @throws AttendanceValidationException
     */
    private function validateAndReserveBalance(
        MappedAttendanceCode $mapped,
        int $userId,
        int $year,
        string $date,
        array &$pendingDeductions,
        array &$pendingRefunds,
        array $leaveTypeCodesById,
    ): void {
        $deduction = $this->ledgerDeductionForDate($mapped, $date);

        // Weekend (or zero-charge) leave: accept the attendance log, skip ledger math.
        if ($deduction <= 0.0) {
            return;
        }

        $balanceLeaveTypeId = $mapped->balanceLeaveTypeId;
        $balanceKey = $this->balanceKey($userId, (int) $balanceLeaveTypeId, $year);

        $balanceRecord = UserYearlyLeaveRecord::query()
            ->where('user_id', $userId)
            ->where('leave_type_id', $balanceLeaveTypeId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        $leaveTypeCode = $leaveTypeCodesById[$balanceLeaveTypeId]
            ?? ($mapped->parentCode ?? $mapped->submittedCode);

        if ($balanceRecord === null) {
            throw AttendanceValidationException::missingBalanceRecord($userId, $leaveTypeCode, $year);
        }

        $currentRemaining = $balanceRecord->remaining_days;
        $alreadyPending = $pendingDeductions[$balanceKey] ?? 0.0;
        $alreadyRefunded = $pendingRefunds[$balanceKey] ?? 0.0;
        // remaining + batch refunds must cover cumulative pending deductions + this charge.
        $effectiveRemaining = round($currentRemaining + $alreadyRefunded - $alreadyPending, 2);

        if ($effectiveRemaining < $deduction) {
            throw AttendanceValidationException::insufficientBalance(
                userId: $userId,
                leaveTypeCode: $leaveTypeCode,
                remaining: $effectiveRemaining,
                deduction: $deduction,
            );
        }

        $pendingDeductions[$balanceKey] = round($alreadyPending + $deduction, 2);

        $balanceRecord->taken_days = round((float) $balanceRecord->taken_days + $deduction, 2);
        $balanceRecord->save();
    }
}
