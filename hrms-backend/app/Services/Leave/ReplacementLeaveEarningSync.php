<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\UserYearlyLeaveRecord;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Syncs earned Replacement Leave credits into user_yearly_leave_records.assigned_days.
 *
 * When Present (`O`) is logged on a Malaysian holiday, assigned_days for leave type `R`
 * increments by 1 for that calendar year. Changing away from Present reverses the credit.
 *
 * Runs inside the attendance write transaction so ledger and attendance_logs stay atomic.
 * Reversal is blocked when the credit has already been consumed (taken_days).
 */
class ReplacementLeaveEarningSync
{
    /** Attendance code for Present (office). */
    public const PRESENT_CODE = 'O';

    /** Attendance / leave type code for Replacement Leave. */
    public const REPLACEMENT_LEAVE_CODE = 'R';

    /** Holiday region that earns replacement leave when worked. */
    public const CREDIT_HOLIDAY_TYPE = 'malaysia';

    /**
     * Apply assigned_days delta after an attendance create or code change.
     *
     * @param  string|null  $previousCode  Null when creating a new attendance log.
     *
     * @throws ValidationException When reversing would drop assigned_days below taken_days.
     */
    public function syncAfterAttendanceChange(
        int $userId,
        string $date,
        ?string $previousCode,
        string $newCode,
    ): void {
        if (! $this->isMalaysianHoliday($date)) {
            return;
        }

        $wasEarned = $this->isPresentCode($previousCode);
        $isEarned = $this->isPresentCode($newCode);

        if ($wasEarned === $isEarned) {
            return;
        }

        $delta = $isEarned ? 1.0 : -1.0;
        $this->adjustAssignedDays($userId, $date, $delta);
    }

    private function isMalaysianHoliday(string $date): bool
    {
        $normalizedDate = Carbon::parse($date)->toDateString();

        return Holiday::query()
            ->whereDate('date', $normalizedDate)
            ->where('type', self::CREDIT_HOLIDAY_TYPE)
            ->exists();
    }

    private function isPresentCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return strtoupper(trim($code)) === self::PRESENT_CODE;
    }

    /**
     * @throws ValidationException
     */
    private function adjustAssignedDays(int $userId, string $date, float $delta): void
    {
        $leaveTypeR = LeaveType::query()
            ->where('leave_type_code', self::REPLACEMENT_LEAVE_CODE)
            ->first();

        // Catalog misconfiguration: do not block Present submission if R is absent.
        if ($leaveTypeR === null) {
            return;
        }

        $year = (int) Carbon::parse($date)->format('Y');

        $record = UserYearlyLeaveRecord::query()
            ->where('user_id', $userId)
            ->where('leave_type_id', $leaveTypeR->id)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            $record = UserYearlyLeaveRecord::query()->create([
                'user_id' => $userId,
                'leave_type_id' => $leaveTypeR->id,
                'year' => $year,
                'assigned_days' => 0.0,
                'taken_days' => 0.0,
            ]);
        }

        $nextAssigned = round((float) $record->assigned_days + $delta, 2);
        $takenDays = (float) $record->taken_days;

        // Ledger integrity: never revoke a credit that has already been consumed as R.
        if ($nextAssigned < $takenDays) {
            throw ValidationException::withMessages([
                'attendance' => 'Cannot change attendance: The earned Replacement Leave for this day has already been consumed.',
            ]);
        }

        $record->assigned_days = max(0.0, $nextAssigned);
        $record->save();
    }
}
