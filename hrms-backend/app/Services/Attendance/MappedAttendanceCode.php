<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * Value object produced by {@see AttendanceVariantMapper}.
 *
 * Encapsulates the resolved persistence and balance-deduction instructions
 * for a single frontend attendance code submission.
 */
final class MappedAttendanceCode
{
    /**
     * @param  int|null  $leaveTypeId  FK for attendance_logs.leave_type_id (null when no leave_types row).
     * @param  int|null  $balanceLeaveTypeId  Parent leave_type_id for user_yearly_leave_records lookup.
     * @param  float  $deductionAmount  Days to increment taken_days (0 when not quota-based).
     * @param  bool  $requiresBalanceCheck  Driven by leave_types.is_quota_based — skips remaining_days when false.
     */
    public function __construct(
        public readonly ?int $leaveTypeId,
        public readonly ?int $balanceLeaveTypeId,
        public readonly float $deductionAmount,
        public readonly bool $requiresBalanceCheck,
        public readonly string $submittedCode,
        public readonly ?string $parentCode = null,
    ) {}
}
