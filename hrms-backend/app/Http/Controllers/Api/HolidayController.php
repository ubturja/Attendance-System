<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHolidayRequest;
use App\Http\Requests\Admin\UpdateHolidayRequest;
use App\Models\AttendanceLog;
use App\Models\Holiday;
use Carbon\Carbon;
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
     *
     * Malaysian holidays cannot be created on a date that already has attendance —
     * Present rows would otherwise receive Replacement Leave credits after the fact.
     */
    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (($data['type'] ?? null) === 'malaysia') {
            $hasAttendance = AttendanceLog::query()
                ->whereDate('date', $data['date'])
                ->exists();

            if ($hasAttendance) {
                return response()->json([
                    'message' => 'Cannot create a Malaysian holiday on a date that already has attendance records. Clear them first.',
                ], 422);
            }
        }

        $holiday = Holiday::query()->create($data);

        return response()->json([
            'success' => true,
            'message' => 'Holiday created successfully.',
            'data' => $holiday,
        ], 201);
    }

    /**
     * Update an existing holiday entry (Admin only).
     *
     * Date and type are locked when attendance already exists on the original
     * holiday date — Admin must clear those logs via Daily Report first so any
     * Replacement Leave credits reverse safely before the calendar changes.
     */
    public function update(UpdateHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $data = $request->validated();

        $originalDate = $holiday->date->toDateString();
        $dateChanging = array_key_exists('date', $data)
            && $originalDate !== Carbon::parse($data['date'])->toDateString();
        $typeChanging = array_key_exists('type', $data)
            && $data['type'] !== $holiday->type;

        if ($dateChanging || $typeChanging) {
            $hasAttendance = AttendanceLog::query()
                ->whereDate('date', $originalDate)
                ->exists();

            if ($hasAttendance) {
                return response()->json([
                    'message' => 'Cannot change the date or type of a holiday that already has attendance records. Please use the Daily Report to clear the attendance first.',
                ], 422);
            }
        }

        $holiday->fill($data);
        $holiday->save();

        return response()->json([
            'success' => true,
            'message' => 'Holiday updated successfully.',
            'data' => $holiday,
        ], 200);
    }

    /**
     * Soft-delete (archive) a holiday entry (Admin only).
     */
    public function destroy(Holiday $holiday): JsonResponse
    {
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
     * Malaysian holidays cannot be restored while any attendance exists on that
     * date — including Present rows created while trashed, which would bypass
     * ledger sync and corrupt credits on later reversal.
     */
    public function restore(int $id): JsonResponse
    {
        $holiday = Holiday::withTrashed()->findOrFail($id);

        if ($holiday->type === 'malaysia') {
            $hasAttendance = AttendanceLog::query()
                ->whereDate('date', $holiday->date->toDateString())
                ->whereNotNull('submitted_code')
                ->where('submitted_code', '!=', '')
                ->exists();

            if ($hasAttendance) {
                return response()->json([
                    'message' => 'Cannot restore Malaysian holiday: Attendance records exist on this date. Clear them first to ensure ledger integrity.',
                ], 422);
            }
        }

        $holiday->restore();

        return response()->json([
            'success' => true,
            'message' => 'Holiday restored.',
            'data' => $holiday->fresh(),
        ], 200);
    }
}
