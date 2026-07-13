<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLeaveTypeRequest;
use App\Http\Requests\Admin\UpdateLeaveTypeRequest;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;

/**
 * Leave type API for dynamic attendance dropdowns and Admin catalog management.
 *
 * Route middleware (when wired):
 * - index  → auth:sanctum (any authenticated Admin or Employee)
 * - store  → auth:sanctum + role:Admin
 * - update → auth:sanctum + role:Admin
 *
 * Non-leave codes (W, O, X) are handled at the application layer and never
 * appear in this table — see HighLevelArchitecture.md.
 */
class LeaveTypeController extends Controller
{
    /**
     * Return active leave types for frontend attendance dropdowns.
     *
     * Authenticated endpoint — any valid Sanctum token (Admin or Employee).
     * Filters WHERE is_active = true per HighLevelArchitecture dynamic dropdown logic.
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "message": "Active leave types retrieved successfully.",
     *   "data": [
     *     {
     *       "id": 1,
     *       "leave_type_code": "A",
     *       "name": "Annual Leave",
     *       "is_active": true
     *     }
     *   ]
     * }
     */
    public function index(): JsonResponse
    {
        // Scope to active records only — inactive types hidden from attendance UI.
        $leaveTypes = LeaveType::query()
            ->where('is_active', true)
            ->orderBy('leave_type_code')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Active leave types retrieved successfully.',
            'data' => $leaveTypes,
        ], 200);
    }

    /**
     * Return all leave types for the Admin catalog screen (active and inactive).
     */
    public function adminIndex(): JsonResponse
    {
        $leaveTypes = LeaveType::query()
            ->orderBy('leave_type_code')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Leave types retrieved successfully.',
            'data' => $leaveTypes,
        ], 200);
    }

    /**
     * Create a new leave type category (Admin only).
     *
     * StoreLeaveTypeRequest validates leave_type_code uniqueness and name length.
     * is_active defaults to true via database default — not accepted on create.
     *
     * JSON response (201):
     * {
     *   "success": true,
     *   "message": "Leave type created successfully.",
     *   "data": {
     *     "id": 5,
     *     "leave_type_code": "MRG",
     *     "name": "Marriage Leave",
     *     "is_active": true
     *   }
     * }
     *
     * Validation failure (422):
     * {
     *   "success": false,
     *   "message": "Validation failed.",
     *   "errors": { "leave_type_code": ["..."] }
     * }
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
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "message": "Leave type updated successfully.",
     *   "data": {
     *     "id": 2,
     *     "leave_type_code": "S",
     *     "name": "Sick Leave",
     *     "is_active": false
     *   }
     * }
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
}
