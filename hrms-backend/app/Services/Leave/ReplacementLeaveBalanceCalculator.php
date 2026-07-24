<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Models\AttendanceLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * 30-day rolling Replacement Leave balance.
 *
 * Credits: Malaysian holidays in the window where attendance was Present (`O`).
 * Debits: Replacement Leave (`R`) taken on/after the oldest still-valid credit date
 *         (not every R still lingering in the 30-day window).
 * Balance: max(0, earned_credits − used_against_current_credits).
 *          When there are no valid credits, balance is strictly 0.
 *
 * This prevents asymmetric negatives when an earned credit slides out of the
 * window while an older R usage against that expired credit remains inside it.
 */
class ReplacementLeaveBalanceCalculator
{
    /** Inclusive lookback length in calendar days (today + previous 29 days). */
    public const WINDOW_DAYS = 30;

    /** Attendance code for Present (office). */
    public const PRESENT_CODE = 'O';

    /** Attendance code for Replacement Leave. */
    public const REPLACEMENT_LEAVE_CODE = 'R';

    /** Holiday region that earns replacement leave when worked. */
    public const CREDIT_HOLIDAY_TYPE = 'malaysia';

    /**
     * Compute the authenticated user's rolling replacement-leave balance as of a date.
     *
     * @return array{
     *     balance: int,
     *     holidays_worked: int,
     *     leaves_taken: int,
     *     window_start: string,
     *     window_end: string,
     *     window_days: int,
     *     holidays_worked_dates: list<string>,
     *     leaves_taken_dates: list<string>,
     *     oldest_valid_credit_date: string|null
     * }
     */
    public function forUser(int $userId, CarbonInterface|string|null $asOf = null): array
    {
        $windowEnd = $this->resolveAsOf($asOf);
        $windowStart = $windowEnd->copy()->subDays(self::WINDOW_DAYS - 1);

        $windowStartDate = $windowStart->toDateString();
        $windowEndDate = $windowEnd->toDateString();

        // Valid earned credits: Present (`O`) on a Malaysian holiday inside the window.
        $holidaysWorkedDates = AttendanceLog::query()
            ->where('user_id', $userId)
            ->where('submitted_code', self::PRESENT_CODE)
            ->whereBetween('date', [$windowStartDate, $windowEndDate])
            ->whereExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('holidays')
                    ->whereColumn('holidays.date', 'attendance_logs.date')
                    ->where('holidays.type', self::CREDIT_HOLIDAY_TYPE);
            })
            ->orderBy('date')
            ->pluck('date')
            ->map(static fn ($date): string => Carbon::parse($date)->toDateString())
            ->values()
            ->all();

        $earnedCredits = count($holidaysWorkedDates);

        // No unexpired credits → nothing available (ignore orphan R rows still in window).
        if ($earnedCredits === 0) {
            return [
                'balance' => 0,
                'holidays_worked' => 0,
                'leaves_taken' => 0,
                'window_start' => $windowStartDate,
                'window_end' => $windowEndDate,
                'window_days' => self::WINDOW_DAYS,
                'holidays_worked_dates' => [],
                'leaves_taken_dates' => [],
                'oldest_valid_credit_date' => null,
            ];
        }

        $oldestValidCreditDate = $holidaysWorkedDates[0];

        // Only R taken since the oldest currently valid credit can consume those credits.
        $leavesTakenDates = AttendanceLog::query()
            ->where('user_id', $userId)
            ->where('submitted_code', self::REPLACEMENT_LEAVE_CODE)
            ->whereBetween('date', [$oldestValidCreditDate, $windowEndDate])
            ->orderBy('date')
            ->pluck('date')
            ->map(static fn ($date): string => Carbon::parse($date)->toDateString())
            ->values()
            ->all();

        $usedAgainstCurrentCredits = count($leavesTakenDates);

        return [
            'balance' => max(0, $earnedCredits - $usedAgainstCurrentCredits),
            'holidays_worked' => $earnedCredits,
            'leaves_taken' => $usedAgainstCurrentCredits,
            'window_start' => $windowStartDate,
            'window_end' => $windowEndDate,
            'window_days' => self::WINDOW_DAYS,
            'holidays_worked_dates' => $holidaysWorkedDates,
            'leaves_taken_dates' => $leavesTakenDates,
            'oldest_valid_credit_date' => $oldestValidCreditDate,
        ];
    }

    private function resolveAsOf(CarbonInterface|string|null $asOf): Carbon
    {
        if ($asOf instanceof CarbonInterface) {
            return Carbon::instance($asOf)->startOfDay();
        }

        if (is_string($asOf) && $asOf !== '') {
            return Carbon::parse($asOf)->startOfDay();
        }

        return now()->startOfDay();
    }
}
