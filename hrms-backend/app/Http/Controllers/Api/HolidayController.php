<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHolidayRequest;
use App\Http\Requests\Admin\UpdateHolidayRequest;
use App\Models\AttendanceLog;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Holiday calendar API for authenticated read access and Admin CRUD.
 *
 * Secured at the route layer: index requires auth (Admin or Employee);
 * store / update / destroy / restore require role:Admin.
 * Soft-deleted rows are hidden from index unless an Admin passes ?include_trashed=true.
 */
class HolidayController extends Controller
{
    /**
     * Return holidays ordered by date.
     *
     * Authenticated endpoint — any valid Sanctum token (Admin or Employee).
     * Admins may pass ?include_trashed=true to include soft-deleted rows.
     * Employees always receive non-trashed holidays only.
     */
    public function index(Request $request): JsonResponse
    {
        $includeTrashed = $request->query('include_trashed') === 'true'
            && $request->user()?->job_title === 'Admin';

        $holidays = $includeTrashed
            ? Holiday::withTrashed()->orderBy('date', 'desc')->get()
            : Holiday::query()->orderBy('date')->get();

        return response()->json([
            'success' => true,
            'message' => 'Holidays retrieved successfully.',
            'data' => $holidays,
        ], 200);
    }

    /**
     * Create a new holiday entry (Admin only).
     */
    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $holiday = Holiday::query()->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Holiday created successfully.',
            'data' => $holiday,
        ], 201);
    }

    /**
     * Update an existing holiday entry (Admin only).
     */
    public function update(UpdateHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $holiday->fill($request->validated());
        $holiday->save();

        return response()->json([
            'success' => true,
            'message' => 'Holiday updated successfully.',
            'data' => $holiday,
        ], 200);
    }

    /**
     * Soft-delete (archive) a holiday entry (Admin only).
     *
     * Malaysian holidays that already have attendance logs cannot be archived —
     * those Present rows may have granted Replacement Leave credits that must be
     * cleared first to keep the yearly ledger consistent.
     */
    public function destroy(Holiday $holiday): JsonResponse
    {
        if ($holiday->type === 'malaysia') {
            $hasAttendance = AttendanceLog::query()
                ->whereDate('date', $holiday->date->toDateString())
                ->exists();

            if ($hasAttendance) {
                return response()->json([
                    'message' => 'Cannot delete holiday: Attendance records exist for this date. Clear the attendance first to reverse any granted leave credits.',
                ], 422);
            }
        }

        $holiday->delete();

        return response()->json([
            'success' => true,
            'message' => 'Holiday deleted successfully.',
            'data' => null,
        ], 200);
    }

    /**
     * Restore a soft-deleted holiday (Admin only).
     *
     * Clears deleted_at so the holiday reappears in the default calendar list.
     */
    public function restore(int $id): JsonResponse
    {
        $holiday = Holiday::withTrashed()->findOrFail($id);
        $holiday->restore();

        return response()->json([
            'success' => true,
            'message' => 'Holiday restored.',
            'data' => $holiday->fresh(),
        ], 200);
    }
}
