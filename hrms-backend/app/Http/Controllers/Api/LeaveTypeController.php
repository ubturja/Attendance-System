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
     * Optional query: ?is_quota_based=true|false filters quota vs attendance-only types.
     * (Legacy alias: ?requires_allocation= still accepted for backward compatibility.)
     */
    public function index(Request $request): JsonResponse
    {
        // Scope to active, non-trashed records only — inactive/archived hidden from attendance UI.
        // Intentionally does NOT default-filter is_quota_based so WFH remains selectable.
        $query = LeaveType::query()
            ->where('is_active', true)
            ->orderBy('leave_type_code');

        if ($request->has('is_quota_based')) {
            $query->where('is_quota_based', $request->boolean('is_quota_based'));
        } elseif ($request->has('requires_allocation')) {
            $query->where('is_quota_based', $request->boolean('requires_allocation'));
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
     * is_quota_based may be supplied; defaults to true when omitted.
     */
    public function store(StoreLeaveTypeRequest $request): JsonResponse
    {
        // Extract validated fields — mass assignment safe via $fillable.
        $validated = $request->validated();

        // Keep legacy requires_allocation in sync with is_quota_based when present.
        if (array_key_exists('is_quota_based', $validated)) {
            $validated['requires_allocation'] = $validated['is_quota_based'];
        }

        // Insert row; is_active / is_quota_based defaults applied by MySQL when omitted.
        $leaveType = LeaveType::query()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Leave type created successfully.',
            'data' => $leaveType,
        ], 201);
    }

    /**
     * Update is_active and/or is_quota_based on an existing leave type (Admin only).
     *
     * Deactivating removes the type from the attendance dropdown without deleting
     * historical FK references. is_quota_based controls Assign Leave / balance grids.
     */
    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('is_quota_based', $validated)) {
            $validated['requires_allocation'] = $validated['is_quota_based'];
        }

        $leaveType->fill($validated);
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
