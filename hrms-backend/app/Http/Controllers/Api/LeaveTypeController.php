<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLeaveTypeRequest;
use App\Http\Requests\Admin\UpdateLeaveTypeRequest;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leave type API for dynamic attendance dropdowns and Admin catalog management.
 *
 * Soft-deleted rows are hidden from adminIndex unless ?status=archived|all.
 * Historical reports resolve names via withTrashed() on leave type relations.
 */
class LeaveTypeController extends Controller
{
    /**
     * Return active leave types for frontend attendance dropdowns.
     *
     * Authenticated endpoint — any valid Sanctum token (Admin or Employee).
     * Filters WHERE is_active = true per HighLevelArchitecture dynamic dropdown logic.
     * Optional query: ?requires_allocation=true|false filters quota vs non-quota types.
     */
    public function index(Request $request): JsonResponse
    {
        // Scope to active, non-trashed records only — inactive/archived hidden from attendance UI.
        $query = LeaveType::query()
            ->where('is_active', true)
            ->orderBy('leave_type_code');

        if ($request->has('requires_allocation')) {
            $query->where('requires_allocation', $request->boolean('requires_allocation'));
        }

        $leaveTypes = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Active leave types retrieved successfully.',
            'data' => $leaveTypes,
        ], 200);
    }

    /**
     * Return leave types for the Admin catalog screen.
     *
     * Query:
     *   ?status=archived → only soft-deleted rows (Admin catalog archive tab).
     *   ?status=all      → active, inactive, and soft-deleted rows (Admin report dropdowns).
     *   default          → non-trashed rows (active and inactive catalog rows).
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $query = LeaveType::query();

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        $leaveTypes = $query
            ->orderBy('leave_type_code')
            ->get();

        return response()->json([
            'success' => true,
            'message' => match ($status) {
                'archived' => 'Archived leave types retrieved successfully.',
                'all' => 'All leave types retrieved successfully.',
                default => 'Leave types retrieved successfully.',
            },
            'data' => $leaveTypes,
        ], 200);
    }

    /**
     * Create a new leave type category (Admin only).
     *
     * StoreLeaveTypeRequest validates leave_type_code uniqueness and name length.
     * is_active defaults to true via database default — not accepted on create.
     */
    public function store(StoreLeaveTypeRequest $request): JsonResponse
    {
        // Extract validated leave_type_code and name — mass assignment safe via $fillable.
        $validated = $request->validated();

        // Insert row; is_active = true applied by MySQL column default (ERD).
        $leaveType = LeaveType::query()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Leave type created successfully.',
            'data' => $leaveType,
        ], 201);
    }

    /**
     * Toggle is_active status on an existing leave type (Admin only).
     *
     * UpdateLeaveTypeRequest accepts only is_active — deactivating removes the
     * type from the index dropdown without deleting historical FK references.
     */
    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        // Single-field update — boolean cast ensures proper MySQL tinyint persistence.
        $leaveType->is_active = $request->validated('is_active');

        // Persist toggle to leave_types table.
        $leaveType->save();

        return response()->json([
            'success' => true,
            'message' => 'Leave type updated successfully.',
            'data' => $leaveType,
        ], 200);
    }

    /**
     * Soft-delete (archive) a leave type (Admin only).
     *
     * Sets deleted_at via SoftDeletes — historical attendance_logs and
     * user_yearly_leave_records retain their FK and remain readable in reports.
     */
    public function destroy(LeaveType $leaveType): JsonResponse
    {
        $leaveType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Leave Type successfully archived.',
            'data' => null,
        ], 200);
    }

    /**
     * Restore a soft-deleted leave type (Admin only).
     *
     * Clears deleted_at so the type reappears in the Admin catalog and,
     * when is_active is true, in the employee attendance dropdown.
     */
    public function restore(int $id): JsonResponse
    {
        $leaveType = LeaveType::withTrashed()->findOrFail($id);
        $leaveType->restore();

        return response()->json([
            'success' => true,
            'message' => 'Leave Type restored.',
            'data' => $leaveType->fresh(),
        ], 200);
    }
}
