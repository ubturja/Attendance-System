<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLeaveRolloverRequest;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Manual HR Control — Leave Allocation Rollover Engine.
 *
 * Secured at the route layer via auth:sanctum + role:Admin middleware.
 *
 * Manual HR workflow (SystemArchitecture.md — Admin allocation management):
 * At year boundary, HR copies each active employee's leave quotas from the closing
 * calendar year into the upcoming year. Consumption counters reset to zero so
 * employees start the new year with full assigned quotas and no prior taken_days.
 *
 * This is an explicit Admin-triggered POST — not a scheduled job — giving HR
 * full control over when rollover executes and the ability to review allocations
 * before the new year goes live.
 *
 * Idempotency guarantee:
 * If a user already has ANY user_yearly_leave_records row for target_year, that
 * user is skipped entirely. Re-running the endpoint cannot double-allocate quotas
 * or overwrite existing target-year balances.
 */
class LeaveRolloverController extends Controller
{
    /**
     * Execute manual leave allocation rollover for all active employees.
     *
     * Request body:
     * {
     *   "source_year": 2026,
     *   "target_year": 2027
     * }
     *
     * JSON response (200):
     * {
     *   "success": true,
     *   "message": "Leave rollover completed successfully.",
     *   "data": {
     *     "source_year": 2026,
     *     "target_year": 2027,
     *     "users_rolled_over": 42,
     *     "users_skipped": 3,
     *     "records_created": 168
     *   }
     * }
     */
    public function store(StoreLeaveRolloverRequest $request): JsonResponse
    {
        $sourceYear = (int) $request->validated('source_year');
        $targetYear = (int) $request->validated('target_year');

        $summary = DB::transaction(function () use ($sourceYear, $targetYear): array {
            $usersRolledOver = 0;
            $usersSkipped = 0;
            $recordsCreated = 0;

            // Process only operational employees — inactive accounts are excluded from rollover.
            $activeUsers = User::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            foreach ($activeUsers as $user) {
                // ── Idempotency check ─────────────────────────────────────────────
                // Skip users who already have target_year rows — prevents double-allocation
                // if HR accidentally triggers rollover twice for the same year.
                $alreadyHasTargetYear = UserYearlyLeaveRecord::query()
                    ->where('user_id', $user->id)
                    ->where('year', $targetYear)
                    ->exists();

                if ($alreadyHasTargetYear) {
                    $usersSkipped++;

                    continue;
                }

                // Fetch closing-year allocation template for this employee.
                $sourceRecords = UserYearlyLeaveRecord::query()
                    ->where('user_id', $user->id)
                    ->where('year', $sourceYear)
                    ->get();

                // Nothing to copy — employee had no allocations in source_year (e.g., new hire).
                if ($sourceRecords->isEmpty()) {
                    $usersSkipped++;

                    continue;
                }

                // ── Duplicate allocations into target_year ──────────────────────
                // Preserve leave_type_id and assigned_days; reset taken_days to 0.
                foreach ($sourceRecords as $sourceRecord) {
                    UserYearlyLeaveRecord::query()->create([
                        'user_id' => $user->id,
                        'leave_type_id' => $sourceRecord->leave_type_id,
                        'year' => $targetYear,
                        'assigned_days' => $sourceRecord->assigned_days,
                        'taken_days' => 0,
                    ]);

                    $recordsCreated++;
                }

                $usersRolledOver++;
            }

            return [
                'source_year' => $sourceYear,
                'target_year' => $targetYear,
                'users_rolled_over' => $usersRolledOver,
                'users_skipped' => $usersSkipped,
                'records_created' => $recordsCreated,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Leave rollover completed successfully.',
            'data' => $summary,
        ], 200);
    }
}
