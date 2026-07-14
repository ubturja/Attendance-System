<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttendanceRequest;
use App\Http\Requests\UpdateAttendanceRequest;
use App\Models\AttendanceLog;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use App\Services\Attendance\AttendanceValidationException;
use App\Services\Attendance\AttendanceVariantMapper;
use App\Services\Attendance\MappedAttendanceCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Core attendance processing controller — fractional deduction and variant mapping.
 *
 * Accepts bulk attendance submissions inside a single DB::transaction().
 * If ANY record fails validation, the entire batch rolls back (all-or-nothing).
 *
 * Authorization (info.md):
 *   - Employee: may only submit for users sharing the same team_id.
 *   - Admin: unrestricted team scope.
 *
 * HighLevelArchitecture.md workflow executed per record:
 *   1. Map frontend code (AO/OA→A 0.5, NO/ON→N 0.5, W/O/X→NULL bypass validation, standard→1.0).
 *   2. Reject duplicate (user_id, date) rows — one log per employee per day.
 *   3. Validate remaining_days will not drop below zero after cumulative deductions.
 *   4. Increment taken_days on user_yearly_leave_records.
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

        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();

        try {
            $this->assertEmployeeTeamScope($authenticatedUser, $records);

            // ── ATOMIC BATCH: all records succeed or none persist ─────────────────
            $createdLogs = DB::transaction(function () use ($records): array {
                $createdLogs = [];

                // In-memory accumulator for cumulative deductions within this batch.
                /** @var array<string, float> $pendingDeductions */
                $pendingDeductions = [];

                // Prevents duplicate (user_id, date) rows within the same request payload.
                /** @var array<string, true> $seenAttendanceKeys */
                $seenAttendanceKeys = [];

                /** @var array<int, string> $leaveTypeCodesById */
                $leaveTypeCodesById = LeaveType::query()
                    ->pluck('leave_type_code', 'id')
                    ->all();

                foreach ($records as $record) {
                    $createdLogs[] = $this->processRecord(
                        record: $record,
                        pendingDeductions: $pendingDeductions,
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
     * @param  array{user_id: int, date: string, code: string}  $record
     * @param  array<string, float>  $pendingDeductions
     * @param  array<int, string>  $leaveTypeCodesById
     * @param  array<string, true>  $seenAttendanceKeys
     *
     * @throws AttendanceValidationException
     */
    private function processRecord(
        array $record,
        array &$pendingDeductions,
        array $leaveTypeCodesById,
        array &$seenAttendanceKeys,
    ): AttendanceLog {
        $userId = (int) $record['user_id'];
        $date = $record['date'];

        $this->assertNoDuplicateAttendance($userId, $date, $seenAttendanceKeys);

        $mapped = $this->variantMapper->map($record['code']);

        $targetUser = User::query()->findOrFail($userId);

        if ($targetUser->team_id === null) {
            throw AttendanceValidationException::userMustBeAssignedToTeam($targetUser->id);
        }

        $year = (int) date('Y', strtotime($date));

        if ($mapped->requiresBalanceCheck) {
            $this->validateAndReserveBalance(
                mapped: $mapped,
                userId: $userId,
                year: $year,
                pendingDeductions: $pendingDeductions,
                leaveTypeCodesById: $leaveTypeCodesById,
            );
        }

        return AttendanceLog::query()->create([
            'user_id' => $targetUser->id,
            'team_id' => $targetUser->team_id,
            'date' => $date,
            'submitted_code' => strtoupper(trim($record['code'])),
            'leave_type_id' => $mapped->leaveTypeId,
        ]);
    }

    /**
     * Admin override — replace the attendance code on an existing log row.
     *
     * Reverses the prior balance deduction, validates the new code, and persists
     * the updated leave_type_id + submitted_code inside a single transaction.
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "message": "Attendance record updated successfully.",
     *   "data": { "id": 1, "submitted_code": "AO", "leave_type_id": 1 }
     * }
     */
    public function update(UpdateAttendanceRequest $request, AttendanceLog $attendanceLog): JsonResponse
    {
        $newCode = strtoupper(trim($request->validated('code')));

        try {
            $updatedLog = DB::transaction(function () use ($attendanceLog, $newCode): AttendanceLog {
                /** @var AttendanceLog $log */
                $log = AttendanceLog::query()
                    ->whereKey($attendanceLog->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /** @var array<int, string> $leaveTypeCodesById */
                $leaveTypeCodesById = LeaveType::query()
                    ->pluck('leave_type_code', 'id')
                    ->all();

                $oldCode = $this->resolveEffectiveCode($log, $leaveTypeCodesById);

                if ($oldCode === $newCode) {
                    return $log;
                }

                $year = (int) $log->date->format('Y');

                $oldMapped = $this->variantMapper->map($oldCode);
                if ($oldMapped->requiresBalanceCheck) {
                    $this->releaseBalance($oldMapped, (int) $log->user_id, $year);
                }

                $newMapped = $this->variantMapper->map($newCode);
                $pendingDeductions = [];

                if ($newMapped->requiresBalanceCheck) {
                    $this->validateAndReserveBalance(
                        mapped: $newMapped,
                        userId: (int) $log->user_id,
                        year: $year,
                        pendingDeductions: $pendingDeductions,
                        leaveTypeCodesById: $leaveTypeCodesById,
                    );
                }

                $log->submitted_code = $newCode;
                $log->leave_type_id = $newMapped->leaveTypeId;
                $log->save();

                return $log->fresh(['leaveType']);
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
            'data' => [
                'id' => $updatedLog->id,
                'user_id' => $updatedLog->user_id,
                'team_id' => $updatedLog->team_id,
                'date' => $updatedLog->date->toDateString(),
                'submitted_code' => $updatedLog->submitted_code,
                'leave_type_id' => $updatedLog->leave_type_id,
            ],
        ], 200);
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
     * Reverse a prior balance deduction when an Admin overrides an attendance code.
     */
    private function releaseBalance(
        MappedAttendanceCode $mapped,
        int $userId,
        int $year,
    ): void {
        $balanceLeaveTypeId = $mapped->balanceLeaveTypeId;
        $deduction = $mapped->deductionAmount;

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
     * Reject duplicate submissions for the same (user_id, date).
     *
     * @param  array<string, true>  $seenAttendanceKeys
     *
     * @throws AttendanceValidationException
     */
    private function assertNoDuplicateAttendance(
        int $userId,
        string $date,
        array &$seenAttendanceKeys,
    ): void {
        $attendanceKey = "{$userId}_{$date}";

        if (isset($seenAttendanceKeys[$attendanceKey])) {
            throw AttendanceValidationException::attendanceAlreadyLogged($userId, $date);
        }

        $alreadyLogged = AttendanceLog::query()
            ->where('user_id', $userId)
            ->whereDate('date', $date)
            ->lockForUpdate()
            ->exists();

        if ($alreadyLogged) {
            throw AttendanceValidationException::attendanceAlreadyLogged($userId, $date);
        }

        $seenAttendanceKeys[$attendanceKey] = true;
    }

    /**
     * Validate remaining_days and increment taken_days on the parent balance row.
     *
     * @param  array<string, float>  $pendingDeductions
     * @param  array<int, string>  $leaveTypeCodesById
     *
     * @throws AttendanceValidationException
     */
    private function validateAndReserveBalance(
        MappedAttendanceCode $mapped,
        int $userId,
        int $year,
        array &$pendingDeductions,
        array $leaveTypeCodesById,
    ): void {
        $balanceLeaveTypeId = $mapped->balanceLeaveTypeId;
        $deduction = $mapped->deductionAmount;
        $balanceKey = "{$userId}_{$balanceLeaveTypeId}_{$year}";

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
        $effectiveRemaining = round($currentRemaining - $alreadyPending, 2);

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
