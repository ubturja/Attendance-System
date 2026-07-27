<?php

use App\Http\Controllers\Api\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Api\Admin\LeaveRolloverController;
use App\Http\Controllers\Api\Admin\TeamController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HolidayController;
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

    // ── Personal dashboard (Admin + Employee) ────────────────────────────────
    // Controllers scope all data via $request->user() — no role-specific user IDs.
    Route::middleware(['role:Admin,Employee'])->group(function (): void {
        // Module 4: bulk attendance submission (self / team-scoped for Employees).
        Route::post('/attendance', [AttendanceController::class, 'store']);

        // Profile + current-year leave balances + team roster for the dashboard.
        // Also embeds holiday-for-date for HK lockdown / MY Present-only UX.
        Route::get('/profile', [ProfileController::class, 'show']);

        // Module 3 (read): active leave types for attendance dropdowns.
        Route::get('/leave-types', [LeaveTypeController::class, 'index']);

        // Holiday calendar (read): all authenticated users.
        Route::get('/holidays', [HolidayController::class, 'index']);
    });

    // Holiday calendar (write): Admin-only create / update / delete / restore.
    Route::post('/holidays', [HolidayController::class, 'store'])
        ->middleware(['role:Admin']);
    Route::put('/holidays/{holiday}', [HolidayController::class, 'update'])
        ->middleware(['role:Admin']);
    Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])
        ->middleware(['role:Admin']);
    Route::post('/holidays/{id}/restore', [HolidayController::class, 'restore'])
        ->middleware(['role:Admin']);

    // Admin daily-report correction — replaces code on an existing log row.
    Route::put('/attendance/{attendanceLog}', [AttendanceController::class, 'update'])
        ->middleware(['role:Admin']);

    // ── Module 2 & 3 (Admin): Team, User, Leave, and Allocation management ───
    Route::middleware(['role:Admin'])->prefix('admin')->group(function (): void {

        // Module 2: Team & User Management API — full CRUD for HR Admins.
        // show allows soft-deleted users so Leave Balance / Assign Leave modals work for archived accounts.
        Route::apiResource('users', UserController::class)->withTrashed(['show']);
        Route::patch('/users/{id}/restore', [UserController::class, 'restore']);
        Route::apiResource('teams', TeamController::class);
        Route::patch('/teams/{id}/restore', [TeamController::class, 'restore']);

        // ── Module 3: Dynamic Leave — Admin catalog mutations (create, toggle, archive, restore).
        Route::get('/leave-types', [LeaveTypeController::class, 'adminIndex']);
        Route::post('/leave-types', [LeaveTypeController::class, 'store']);
        Route::put('/leave-types/{leaveType}', [LeaveTypeController::class, 'update']);
        Route::delete('/leave-types/{leaveType}', [LeaveTypeController::class, 'destroy']);
        Route::patch('/leave-types/{id}/restore', [LeaveTypeController::class, 'restore']);

        // Module 3: Leave Allocation — yearly balance assignment and adjustment.
        Route::get('/leave-allocations', [LeaveAllocationController::class, 'index']);
        Route::get('/leave-allocations/{user}', [LeaveAllocationController::class, 'show'])->withTrashed();
        Route::put('/leave-allocations/{user}', [LeaveAllocationController::class, 'update'])->withTrashed();

        // Module 3: Manual HR Control — copy source_year allocations to target_year (idempotent).
        Route::post('/leave-rollover', [LeaveRolloverController::class, 'store']);

        // Module 5 (Admin alias): Daily report + attendance upsert with audit trail.
        Route::get('/reports/daily', [ReportController::class, 'daily']);
        Route::post('/reports/daily/update', [AdminAttendanceController::class, 'adminUpdate']);
    });

    // ── Module 5: Pivot Reporting Engine (Admin only) ────────────────────────
    // Daily, monthly matrix, and yearly flattened pivot reports.
    Route::middleware(['role:Admin'])->prefix('reports')->group(function (): void {
        Route::get('/daily', [ReportController::class, 'daily']);
        Route::get('/monthly', [ReportController::class, 'monthly']);
        Route::get('/yearly', [ReportController::class, 'yearly']);
    });
});
