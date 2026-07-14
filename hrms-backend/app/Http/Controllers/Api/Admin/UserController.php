<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Admin CRUD controller for HRMS user account management.
 *
 * Secured at the route layer via auth:sanctum + role:Admin middleware.
 * PRD: Admins provision accounts (no self-registration); name, email, and
 * passport_number are immutable after creation.
 */
class UserController extends Controller
{
    /**
     * List all user accounts with optional team relationship eager-loaded.
     */
    public function index(): JsonResponse
    {
        $currentYear = (int) date('Y');

        // Eager-load team and current-year balances for Admin list rendering.
        $users = User::query()
            ->with([
                'team',
                'yearlyLeaveRecords' => static function ($query) use ($currentYear): void {
                    $query
                        ->where('year', $currentYear)
                        ->with('leaveType');
                },
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully.',
            'data' => $users,
        ], 200);
    }

    /**
     * Persist a new user account with a bcrypt-hashed password.
     *
     * Atomically seeds zero-balance user_yearly_leave_records for every active
     * leave type with requires_allocation = true so HR can immediately open the
     * new hire's allocation profile and set assigned_days baselines. Non-quota
     * statuses (e.g. Work from Home) are excluded from the balance sheet.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        // StoreUserRequest validates shape/uniqueness — extract only whitelisted attributes.
        $validated = $request->validated();

        // Hash plaintext password via Hash facade before persistence (enterprise security requirement).
        // Model 'hashed' cast detects pre-hashed values and will not double-hash.
        $validated['password'] = Hash::make($validated['password']);

        // Entire provisioning flow is atomic — user row and default leave rows succeed or fail together.
        $user = DB::transaction(function () use ($validated): User {
            // Mass assignment protected by User::$fillable whitelist.
            $user = User::query()->create($validated);

            $currentYear = (int) date('Y');

            // Quota leave types only — exclude non-allocation codes (e.g. W = Work from Home).
            $activeLeaveTypes = LeaveType::query()
                ->where('is_active', true)
                ->where('requires_allocation', true)
                ->orderBy('leave_type_code')
                ->get();

            // Initialize one balance row per quota leave type for the current calendar year.
            // assigned_days / taken_days start at 0.00 so HR can edit quotas via LeaveAllocationController.
            foreach ($activeLeaveTypes as $leaveType) {
                UserYearlyLeaveRecord::query()->create([
                    'user_id' => $user->id,
                    'leave_type_id' => $leaveType->id,
                    'year' => $currentYear,
                    'assigned_days' => 0.00,
                    'taken_days' => 0.00,
                ]);
            }

            return $user;
        });

        // Reload team relation for consistent response shape with index/show endpoints.
        $user->load('team');

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => $user,
        ], 201);
    }

    /**
     * Retrieve a single user with team and complete yearly leave balances.
     *
     * Eager-loads yearlyLeaveRecords.leaveType so the Admin popup receives
     * assigned_days, taken_days, and the appended remaining_days
     * (assigned − taken) for every leave type on this user's balance sheet.
     */
    public function show(User $user): JsonResponse
    {
        // Route-model binding resolves User or returns 404 before this method executes.
        $user->load([
            'team',
            'yearlyLeaveRecords' => static function ($query): void {
                $query
                    ->orderByDesc('year')
                    ->orderBy('leave_type_id')
                    ->with('leaveType');
            },
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully.',
            'data' => $user,
        ], 200);
    }

    /**
     * Update mutable user profile fields (PRD-immutable fields excluded by UpdateUserRequest).
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        // UpdateUserRequest blocks name, email, passport_number at validation boundary.
        $validated = $request->validated();

        // fill() respects $fillable — only vetted keys are written to the row.
        $user->fill($validated);

        // Persist changes to MySQL users table.
        $user->save();

        $user->load('team');

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => $user,
        ], 200);
    }

    /**
     * Remove a user account from the system.
     *
     * DB-level restrictOnDelete on attendance_logs may block hard deletes
     * when historical records exist — surfaced as a query exception upstream.
     */
    public function destroy(User $user): JsonResponse
    {
        // Hard delete — restrict FKs on attendance_logs enforce audit trail integrity.
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully.',
        ], 200);
    }
}
