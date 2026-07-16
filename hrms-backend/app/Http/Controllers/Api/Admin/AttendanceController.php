<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\AttendanceController as BaseAttendanceController;
use App\Http\Requests\Admin\AdminDailyAttendanceUpdateRequest;
use Illuminate\Http\JsonResponse;

/**
 * Admin attendance upsert for daily-report corrections.
 *
 * Delegates to the shared AttendanceController::adminUpdate implementation so
 * balance reverse/reserve and updated_by audit stamping stay in one place.
 */
class AttendanceController extends BaseAttendanceController
{
    /**
     * Create or update an attendance log for a user on a given date.
     */
    public function adminUpdate(AdminDailyAttendanceUpdateRequest $request): JsonResponse
    {
        return parent::adminUpdate($request);
    }
}
