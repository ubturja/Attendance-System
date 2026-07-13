<?php

use App\Http\Controllers\Api\Admin\LeaveRolloverController;
use App\Http\Controllers\Api\Admin\TeamController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaveAllocationController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HRMS API Routes
|--------------------------------------------------------------------------
|
| Stateless Sanctum token authentication. Public login; all other endpoints
| require auth:sanctum. Admin-only modules additionally enforce role:Admin.
|
| SystemArchitecture.md module map:
|   1. Authentication & Middleware
|   2. Team & User Management API          → /admin/*
|   3. Dynamic Leave & Allocation API      → /admin/*
|   4. Fractional Attendance Processing    → /attendance
|   5. Pivot Reporting Engine              → /reports/*
|
*/

// ── Module 1: Authentication & Middleware ────────────────────────────────────
// Public credential exchange — issues Sanctum Bearer token (no prior auth required).
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {

    // Token revocation — authenticated users only.
    Route::post('/logout', [AuthController::class, 'logout']);

    // ── Module 4: Fractional Attendance Processing API (Employee + Admin) ────
    // Bulk attendance submission with variant mapping (AO/OA→A 0.5, etc.).
    Route::post('/attendance', [AttendanceController::class, 'store']);
    // Admin daily-report correction — replaces code on an existing log row.
    Route::put('/attendance/{attendanceLog}', [AttendanceController::class, 'update'])
        ->middleware(['role:Admin']);

    // Employee read-only dashboard — profile + team-scoped colleague roster.
    Route::get('/profile', [ProfileController::class, 'show']);

    // ── Module 3 (read): Dynamic Leave — active types for attendance dropdowns ─ // HighLevelArchitecture.md: GET /api/leave-types (WHERE is_active = true).
    Route::get('/leave-types', [LeaveTypeController::class, 'index']);

    // ── Module 2 & 3 (Admin): Team, User, Leave, and Allocation management ───
    Route::middleware(['role:Admin'])->prefix('admin')->group(function (): void {

        // Module 2: Team & User Management API — full CRUD for HR Admins.
        Route::apiResource('users', UserController::class);
        Route::apiResource('teams', TeamController::class);

        // ── Module 3: Dynamic Leave — Admin catalog mutations (create + toggle is_active).
        Route::get('/leave-types', [LeaveTypeController::class, 'adminIndex']);
        Route::post('/leave-types', [LeaveTypeController::class, 'store']);
        Route::put('/leave-types/{leaveType}', [LeaveTypeController::class, 'update']);

        // Module 3: Leave Allocation — yearly balance assignment and adjustment.
        Route::get('/leave-allocations', [LeaveAllocationController::class, 'index']);
        Route::get('/leave-allocations/{user}', [LeaveAllocationController::class, 'show']);
        Route::put('/leave-allocations/{user}', [LeaveAllocationController::class, 'update']);

        // Module 3: Manual HR Control — copy source_year allocations to target_year (idempotent).
        Route::post('/leave-rollover', [LeaveRolloverController::class, 'store']);
    });

    // ── Module 5: Pivot Reporting Engine (Admin only) ────────────────────────
    // Daily, monthly matrix, and yearly flattened pivot reports.
    Route::middleware(['role:Admin'])->prefix('reports')->group(function (): void {
        Route::get('/daily', [ReportController::class, 'daily']);
        Route::get('/monthly', [ReportController::class, 'monthly']);
        Route::get('/yearly', [ReportController::class, 'yearly']);
    });
});
