<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\LeaveType;
use Illuminate\Support\Collection;

/**
 * Maps frontend attendance codes to database persistence and balance rules.
 *
 * HighLevelArchitecture.md fractional deduction workflow:
 *
 * 1. Codes without a leave_types row (e.g. O, X) → leave_type_id NULL, zero deduction.
 * 2. Leave types with is_quota_based = false (e.g. W) → persist FK, zero deduction, no balance check.
 * 3. Half-day variants (AO, OA, NO, ON, SO, OS) → resolve PARENT code, deduct 0.5 days
 *    from the parent's user_yearly_leave_records row when the parent is quota-based.
 * 4. Standard quota leave codes (A, S, M, …) → deduct 1.0 day from matching leave_types row.
 *
 * Variant codes exist only in the frontend — they are NOT rows in leave_types.
 * Deduction / balance checks are driven by leave_types.is_quota_based, not hardcoded code lists.
 */
class AttendanceVariantMapper
{
    /**
     * Frontend-only half-day variants → parent leave_type_code for balance lookup.
     *
     * AO/OA (Annual Morning/Afternoon) both draw 0.5 from parent "A".
     * NO/ON (No Pay Morning/Afternoon) both draw 0.5 from parent "N".
     * SO/OS (Sick Morning/Afternoon) both draw 0.5 from parent "S".
     *
     * @var array<string, string>
     */
    private const VARIANT_TO_PARENT = [
        'AO' => 'A',
        'OA' => 'A',
        'NO' => 'N',
        'ON' => 'N',
        'SO' => 'S',
        'OS' => 'S',
    ];

    /** Full-day leave consumption amount (DECIMAL 8,2 compatible). */
    private const FULL_DAY_DEDUCTION = 1.0;

    /** Half-day variant consumption amount — morning/afternoon fractional tracking. */
    private const HALF_DAY_DEDUCTION = 0.5;

    /** @var Collection<string, LeaveType>|null Cached leave_type_code → LeaveType map for the request lifecycle. */
    private ?Collection $leaveTypesByCode = null;

    /**
     * Resolve a submitted frontend code into persistence and deduction instructions.
     *
     * @throws AttendanceValidationException When a half-day variant's parent leave type is missing.
     */
    public function map(string $submittedCode): MappedAttendanceCode
    {
        $code = strtoupper(trim($submittedCode));
        $leaveTypes = $this->leaveTypesByCode();

        // ── Step 1: Half-day variants (AO, OA, NO, ON, SO, OS) ────────────────
        // Map to PARENT code and apply 0.5-day deduction when the parent is quota-based.
        if (array_key_exists($code, self::VARIANT_TO_PARENT)) {
            $parentCode = self::VARIANT_TO_PARENT[$code];
            /** @var LeaveType|null $parentLeaveType */
            $parentLeaveType = $leaveTypes->get($parentCode);

            if ($parentLeaveType === null) {
                throw AttendanceValidationException::unknownParentLeaveType($code, $parentCode);
            }

            $requiresBalanceCheck = (bool) $parentLeaveType->is_quota_based;
            $leaveTypeId = (int) $parentLeaveType->id;

            return new MappedAttendanceCode(
                leaveTypeId: $leaveTypeId,
                balanceLeaveTypeId: $requiresBalanceCheck ? $leaveTypeId : null,
                deductionAmount: $requiresBalanceCheck ? self::HALF_DAY_DEDUCTION : 0.0,
                requiresBalanceCheck: $requiresBalanceCheck,
                submittedCode: $code,
                parentCode: $parentCode,
            );
        }

        // ── Step 2: Standard codes — resolve LeaveType and honour is_quota_based ─
        /** @var LeaveType|null $leaveType */
        $leaveType = $leaveTypes->get($code);
        $requiresBalanceCheck = $leaveType ? (bool) $leaveType->is_quota_based : false;
        $leaveTypeId = $leaveType !== null ? (int) $leaveType->id : null;

        return new MappedAttendanceCode(
            leaveTypeId: $leaveTypeId,
            balanceLeaveTypeId: $requiresBalanceCheck ? $leaveTypeId : null,
            deductionAmount: $requiresBalanceCheck ? self::FULL_DAY_DEDUCTION : 0.0,
            requiresBalanceCheck: $requiresBalanceCheck,
            submittedCode: $code,
        );
    }

    /**
     * Load leave types keyed by leave_type_code (cached per mapper instance).
     *
     * Includes soft-deleted (archived) rows via withTrashed() so historical
     * attendance codes still resolve for balance refunds when a policy is archived.
     *
     * @return Collection<string, LeaveType>
     */
    private function leaveTypesByCode(): Collection
    {
        if ($this->leaveTypesByCode === null) {
            $this->leaveTypesByCode = LeaveType::withTrashed()
                ->get()
                ->keyBy('leave_type_code');
        }

        return $this->leaveTypesByCode;
    }
}
