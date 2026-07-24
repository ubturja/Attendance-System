<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHolidayRequest;
use App\Http\Requests\Admin\UpdateHolidayRequest;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;

/**
 * Holiday calendar API for authenticated read access and Admin CRUD.
 *
 * Secured at the route layer: index requires auth (Admin or Employee);
 * store / update / destroy require role:Admin.
 */
class HolidayController extends Controller
{
    /**
     * Return all holidays ordered by date ascending.
     *
     * Authenticated endpoint — any valid Sanctum token (Admin or Employee).
     */
    public function index(): JsonResponse
    {
        $holidays = Holiday::query()
            ->orderBy('date')
            ->get();

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
     * Permanently delete a holiday entry (Admin only).
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
}
