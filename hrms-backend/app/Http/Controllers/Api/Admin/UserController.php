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
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
     * List user accounts with optional team relationship eager-loaded.
     *
     * Query: ?status=archived — only soft-deleted (offboarded) users.
     * Query: ?search=jane — matches name or email (case-insensitive LIKE).
     */
    public function index(Request $request): JsonResponse
    {
        $currentYear = (int) date('Y');
        $search = $request->query('search');
        $isArchived = $request->status === 'archived';

        // Eager-load team and current-year balances for Admin list rendering.
        $usersQuery = User::query()
            ->with([
                'team',
                'yearlyLeaveRecords' => static function ($query) use ($currentYear): void {
                    $query
                        ->where('year', $currentYear)
                        ->with('leaveType');
                },
            ])
            ->orderBy('name');

        if ($isArchived) {
            $usersQuery->onlyTrashed();
        }

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $usersQuery->where(static function ($query) use ($term): void {
                $query
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        $users = $usersQuery->get();

        return response()->json([
            'success' => true,
            'message' => $isArchived
                ? 'Archived users retrieved successfully.'
                : 'Users retrieved successfully.',
            'data' => $users,
        ], 200);
    }

    /**
     * Persist a new user account with a bcrypt-hashed password.
     *
     * Atomically seeds zero-balance user_yearly_leave_records for every active
     * leave type with is_quota_based = true so HR can immediately open the
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

            // Quota leave types only — exclude attendance-only codes (e.g. W = Work from Home).
            $activeLeaveTypes = LeaveType::query()
                ->where('is_active', true)
                ->where('is_quota_based', true)
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
     * Retrieve a single user with team and complete current-year leave balances.
     *
     * Returns every active leave type initialized to 0.0, merged with the user's
     * actual user_yearly_leave_records rows for the current calendar year so the
     * Admin slide-over always renders a full balance sheet.
     */
    public function show(User $user): JsonResponse
    {
        // Route-model binding resolves User or returns 404 before this method executes.
        $user->load('team');

        $currentYear = (int) now()->year;
        $leaveBalances = $this->buildCurrentYearLeaveBalances($user, $currentYear);

        $payload = $user->toArray();
        $payload['yearly_leave_records'] = $leaveBalances;

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully.',
            'data' => $payload,
        ], 200);
    }

    /**
     * Merge active leave types with a user's current-year balance rows.
     *
     * Missing rows are synthesized in-memory (not persisted) with 0.0 balances
     * and the leaveType relation attached for frontend rendering.
     *
     * @return Collection<int, UserYearlyLeaveRecord>
     */
    private function buildCurrentYearLeaveBalances(User $user, int $year): Collection
    {
        // Assign Leave / balance grid — countable quotas only (exclude WFH etc.).
        $allLeaveTypes = LeaveType::query()
            ->where('is_active', true)
            ->where('is_quota_based', true)
            ->orderBy('leave_type_code')
            ->get();

        $userRecords = UserYearlyLeaveRecord::query()
            ->with('leaveType')
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->get()
            ->keyBy('leave_type_id');

        return $allLeaveTypes->map(static function (LeaveType $leaveType) use ($user, $userRecords, $year): UserYearlyLeaveRecord {
            if ($userRecords->has($leaveType->id)) {
                return $userRecords->get($leaveType->id);
            }

            $record = new UserYearlyLeaveRecord([
                'user_id' => $user->id,
                'leave_type_id' => $leaveType->id,
                'year' => $year,
                'assigned_days' => 0.0,
                'taken_days' => 0.0,
            ]);
            $record->setRelation('leaveType', $leaveType);

            return $record;
        })->values();
    }

    /**
     * Update mutable user profile fields (PRD-immutable fields excluded by UpdateUserRequest).
     *
     * Optional password reset: when password is filled, hash and persist it;
     * otherwise leave existing credentials unchanged.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        // UpdateUserRequest blocks name, email, passport_number at validation boundary.
        $validated = $request->validated();

        if ($request->filled('password')) {
            // Hash plaintext before fill — hashed cast skips already-hashed values.
            $validated['password'] = Hash::make($request->password);
        } else {
            unset($validated['password']);
        }

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
     * Soft-delete (archive / offboard) a user account.
     *
     * Sets deleted_at; historical attendance and leave rows remain intact.
     * Soft-deleted users cannot authenticate (Eloquent user provider).
     */
    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User successfully archived.',
            'data' => null,
        ], 200);
    }

    /**
     * Restore a soft-deleted user (Admin only).
     *
     * Clears deleted_at so the account reappears in the active Admin catalog
     * and can authenticate again.
     */
    public function restore(int $id): JsonResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();

        $user->load('team');

        return response()->json([
            'success' => true,
            'message' => 'User restored.',
            'data' => $user,
        ], 200);
    }
}
