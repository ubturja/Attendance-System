<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use Exception;

/**
 * Domain exception for attendance business-rule violations inside DB transactions.
 *
 * Thrown when validation fails; caught by AttendanceController to produce a
 * standardized JSON response after automatic transaction rollback.
 * httpStatus distinguishes 404 (missing allocation row) from 422 (rule violations).
 */
class AttendanceValidationException extends Exception
{
    public const HTTP_FORBIDDEN = 403;

    public const HTTP_UNPROCESSABLE = 422;

    public const HTTP_NOT_FOUND = 404;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
        public readonly int $httpStatus = self::HTTP_UNPROCESSABLE,
    ) {
        parent::__construct($message);
    }

    public static function unknownAttendanceCode(string $code): self
    {
        return new self(
            "Unknown attendance code \"{$code}\".",
            ['code' => $code],
        );
    }

    public static function unknownParentLeaveType(string $variantCode, string $parentCode): self
    {
        return new self(
            "Parent leave type \"{$parentCode}\" for variant \"{$variantCode}\" is not configured.",
            ['code' => $variantCode, 'parent_code' => $parentCode],
        );
    }

    public static function missingBalanceRecord(int $userId, string $leaveTypeCode, int $year): self
    {
        return new self(
            "No leave allocation found for user {$userId}, leave type \"{$leaveTypeCode}\", year {$year}.",
            ['user_id' => $userId, 'leave_type_code' => $leaveTypeCode, 'year' => $year],
            self::HTTP_NOT_FOUND,
        );
    }

    public static function userMustBeAssignedToTeam(int $userId): self
    {
        return new self(
            'User must be assigned to a team before logging attendance.',
            ['user_id' => $userId],
            self::HTTP_UNPROCESSABLE,
        );
    }

    public static function insufficientBalance(
        int $userId,
        string $leaveTypeCode,
        float $remaining,
        float $deduction,
    ): self {
        return new self(
            "Insufficient leave balance for \"{$leaveTypeCode}\". "
            ."Remaining: {$remaining}, requested deduction: {$deduction}.",
            [
                'user_id' => $userId,
                'leave_type_code' => $leaveTypeCode,
                'remaining_days' => $remaining,
                'deduction' => $deduction,
            ],
        );
    }

    public static function employeeMustBelongToTeam(): self
    {
        return new self(
            'Forbidden. You must be assigned to a team before submitting attendance.',
            [],
            self::HTTP_FORBIDDEN,
        );
    }

    public static function forbiddenTeamScope(int $targetUserId): self
    {
        return new self(
            'Forbidden. You may only submit attendance for members of your assigned team.',
            ['user_id' => $targetUserId],
            self::HTTP_FORBIDDEN,
        );
    }

    public static function attendanceAlreadyLogged(int $userId, string $date): self
    {
        return new self(
            'Attendance already logged for this date.',
            ['user_id' => $userId, 'date' => $date],
        );
    }

    public static function insufficientReplacementLeaveBalance(int $userId, string $date, float $balance): self
    {
        return new self(
            'Insufficient Replacement Leave balance or credits have expired.',
            [
                'user_id' => $userId,
                'date' => $date,
                'balance' => $balance,
            ],
            self::HTTP_UNPROCESSABLE,
        );
    }
}
