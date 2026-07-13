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
 * 1. Non-leave codes (W, O, X) → leave_type_id NULL, zero deduction, no balance check.
 * 2. Half-day variants (AO, OA, NO, ON) → resolve PARENT code (A or N), deduct 0.5 days
 *    from the parent's user_yearly_leave_records row, store parent leave_type_id on log.
 * 3. Standard leave codes (A, S, M, …) → deduct 1.0 day from matching leave_types row.
 *
 * Variant codes exist only in the frontend — they are NOT rows in leave_types.
 */
class AttendanceVariantMapper
{
    /**
     * Frontend-only half-day variants → parent leave_type_code for balance lookup.
     *
     * AO/OA (Annual Morning/Afternoon) both draw 0.5 from parent "A".
     * NO/ON (No Pay Morning/Afternoon) both draw 0.5 from parent "N".
     *
     * @var array<string, string>
     */
    private const VARIANT_TO_PARENT = [
        'AO' => 'A',
        'OA' => 'A',
        'NO' => 'N',
        'ON' => 'N',
    ];

    /**
     * Non-leave attendance codes — bypass balance validation, persist NULL FK.
     *
     * @var list<string>
     */
    private const NON_LEAVE_CODES = ['W', 'O', 'X'];

    /** Full-day leave consumption amount (DECIMAL 8,2 compatible). */
    private const FULL_DAY_DEDUCTION = 1.0;

    /** Half-day variant consumption amount — morning/afternoon fractional tracking. */
    private const HALF_DAY_DEDUCTION = 0.5;

    /** @var Collection<string, int>|null Cached leave_type_code → id map for the request lifecycle. */
    private ?Collection $leaveTypeIdsByCode = null;

    /**
     * Resolve a submitted frontend code into persistence and deduction instructions.
     *
     * @throws AttendanceValidationException When the code is unknown or leave type inactive.
     */
    public function map(string $submittedCode): MappedAttendanceCode
    {
        $code = strtoupper(trim($submittedCode));

        // ── Step 1: Non-leave codes (W, O, X) ─────────────────────────────────
        // Insert NULL into attendance_logs.leave_type_id; no balance interaction.
        if (in_array($code, self::NON_LEAVE_CODES, true)) {
            return new MappedAttendanceCode(
                leaveTypeId: null,
                balanceLeaveTypeId: null,
                deductionAmount: 0.0,
                requiresBalanceCheck: false,
                submittedCode: $code,
            );
        }

        $leaveTypes = $this->leaveTypesByCode();

        // ── Step 2: Half-day variants (AO, OA, NO, ON) ────────────────────────
        // Map to PARENT code (A or N) and apply 0.5-day deduction math.
        if (array_key_exists($code, self::VARIANT_TO_PARENT)) {
            $parentCode = self::VARIANT_TO_PARENT[$code];

            if (! $leaveTypes->has($parentCode)) {
                throw AttendanceValidationException::unknownParentLeaveType($code, $parentCode);
            }

            $parentLeaveTypeId = (int) $leaveTypes->get($parentCode);

            return new MappedAttendanceCode(
                leaveTypeId: $parentLeaveTypeId,
                balanceLeaveTypeId: $parentLeaveTypeId,
                deductionAmount: self::HALF_DAY_DEDUCTION,
                requiresBalanceCheck: true,
                submittedCode: $code,
                parentCode: $parentCode,
            );
        }

        // ── Step 3: Standard leave codes (A, S, M, …) ─────────────────────────
        // Direct 1.0-day deduction against the matching leave_types row.
        if (! $leaveTypes->has($code)) {
            throw AttendanceValidationException::unknownAttendanceCode($code);
        }

        $leaveTypeId = (int) $leaveTypes->get($code);

        return new MappedAttendanceCode(
            leaveTypeId: $leaveTypeId,
            balanceLeaveTypeId: $leaveTypeId,
            deductionAmount: self::FULL_DAY_DEDUCTION,
            requiresBalanceCheck: true,
            submittedCode: $code,
        );
    }

    /**
     * Load active leave types keyed by leave_type_code → id (cached per mapper instance).
     *
     * @return Collection<string, int>
     */
    private function leaveTypesByCode(): Collection
    {
        if ($this->leaveTypeIdsByCode === null) {
            $this->leaveTypeIdsByCode = LeaveType::query()
                ->where('is_active', true)
                ->pluck('id', 'leave_type_code');
        }

        return $this->leaveTypeIdsByCode;
    }
}
