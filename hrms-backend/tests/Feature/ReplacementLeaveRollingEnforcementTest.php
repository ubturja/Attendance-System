<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReplacementLeaveRollingEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-24'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_r_submission_succeeds_with_rolling_credit_and_skips_yearly_quota(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployee();

        // No yearly allocation row for R — must still succeed via rolling balance.
        Holiday::query()->create([
            'name' => 'Malaysia Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);
        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $employee->team_id,
            'date' => '2026-07-10',
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-24',
                    'code' => 'R',
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertTrue(
            AttendanceLog::query()
                ->where('user_id', $employee->id)
                ->whereDate('date', '2026-07-24')
                ->where('submitted_code', 'R')
                ->where('leave_type_id', $leaveTypeR->id)
                ->exists(),
        );
        $this->assertDatabaseCount('user_yearly_leave_records', 0);
    }

    public function test_r_submission_rejected_when_rolling_balance_is_zero(): void
    {
        [$employee] = $this->seedEmployee();

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-24',
                    'code' => 'R',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Insufficient Replacement Leave balance or credits have expired.',
        ]);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_r_submission_rejected_when_only_expired_credit_exists(): void
    {
        [$employee] = $this->seedEmployee();

        Holiday::query()->create([
            'name' => 'Expired Malaysia Holiday',
            'date' => '2026-06-20',
            'type' => 'malaysia',
        ]);
        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $employee->team_id,
            'date' => '2026-06-20',
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-24',
                    'code' => 'R',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            'Insufficient Replacement Leave balance or credits have expired.',
        );
    }

    public function test_annual_leave_still_uses_yearly_ledger(): void
    {
        [$employee] = $this->seedEmployee();
        $annual = LeaveType::factory()->annual()->create([
            'is_active' => true,
            'is_quota_based' => true,
        ]);

        UserYearlyLeaveRecord::factory()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'year' => 2026,
            'assigned_days' => 1.0,
            'taken_days' => 0.0,
        ]);

        Sanctum::actingAs($employee);

        $ok = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-24',
                    'code' => 'A',
                ],
            ],
        ]);
        $ok->assertCreated();

        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'taken_days' => 1.0,
        ]);

        // Second annual day must fail on yearly remaining (not rolling).
        // Use a weekday — weekends charge 0.0 against the yearly ledger.
        Carbon::setTestNow(Carbon::parse('2026-07-27'));

        $denied = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-27',
                    'code' => 'A',
                ],
            ],
        ]);
        $denied->assertStatus(422);
        $this->assertStringContainsString('Insufficient leave balance', (string) $denied->json('message'));
    }

    /**
     * @return array{0: User, 1: LeaveType}
     */
    private function seedEmployee(): array
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $leaveTypeR = LeaveType::factory()->create([
            'leave_type_code' => 'R',
            'name' => 'Replacement Leave',
            'is_active' => true,
            'is_quota_based' => true,
            'requires_allocation' => true,
        ]);

        return [$employee, $leaveTypeR];
    }
}
