<?php

declare(strict_types=1);

namespace Tests\Feature;

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

    public function test_r_submission_succeeds_when_yearly_assigned_days_remain(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployee();

        // Credit earned via Present on a Malaysian holiday (Step 1 sync).
        Holiday::query()->create([
            'name' => 'Malaysia Holiday',
            'date' => '2026-07-24',
            'type' => 'malaysia',
        ]);

        Sanctum::actingAs($employee);

        $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-24',
                    'code' => 'O',
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 1.0,
            'taken_days' => 0.0,
        ]);

        // Take Replacement Leave on a later weekday against the yearly ledger.
        Carbon::setTestNow(Carbon::parse('2026-07-27'));

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-27',
                    'code' => 'R',
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 1.0,
            'taken_days' => 1.0,
        ]);
    }

    public function test_r_submission_rejected_when_yearly_remaining_is_zero(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployee();

        UserYearlyLeaveRecord::factory()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 0.0,
            'taken_days' => 0.0,
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
        $this->assertStringContainsString('Insufficient leave balance', (string) $response->json('message'));
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'taken_days' => 0.0,
        ]);
    }

    public function test_r_submission_rejected_when_no_yearly_allocation_row_exists(): void
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

        // Same path as Annual/Sick — missing yearly row is not a rolling credit.
        $response->assertStatus(404);
        $this->assertStringContainsString('No leave allocation found', (string) $response->json('message'));
        $this->assertDatabaseCount('attendance_logs', 0);
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

        // Second annual day must fail on yearly remaining.
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
