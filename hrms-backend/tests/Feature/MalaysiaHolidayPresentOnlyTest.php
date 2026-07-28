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

class MalaysiaHolidayPresentOnlyTest extends TestCase
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

    public function test_store_rejects_non_present_leave_codes_on_malaysia_holiday(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        LeaveType::factory()->create([
            'leave_type_code' => 'R',
            'name' => 'Replacement Leave',
            'is_active' => true,
            'is_quota_based' => true,
        ]);
        LeaveType::factory()->create([
            'leave_type_code' => 'A',
            'name' => 'Annual Leave',
            'is_active' => true,
            'is_quota_based' => true,
        ]);

        Holiday::query()->create([
            'name' => 'Malaysia Holiday',
            'date' => '2026-07-24',
            'type' => 'malaysia',
        ]);

        Sanctum::actingAs($employee);

        foreach (['R', 'A'] as $code) {
            $response = $this->postJson('/api/attendance', [
                'records' => [
                    [
                        'user_id' => $employee->id,
                        'date' => '2026-07-24',
                        'code' => $code,
                    ],
                ],
            ]);

            $response->assertStatus(422);
            $response->assertJsonPath(
                'message',
                'Only "Present" can be submitted on a Malaysian Public Holiday.',
            );
        }

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_store_allows_present_on_malaysia_holiday(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

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

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $employee->id,
            'submitted_code' => 'O',
        ]);
    }

    public function test_employee_can_clear_present_with_x_and_reverse_credit(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $leaveTypeR = LeaveType::factory()->create([
            'leave_type_code' => 'R',
            'name' => 'Replacement Leave',
            'is_active' => true,
            'is_quota_based' => true,
        ]);

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
            'assigned_days' => 1,
        ]);

        $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-24',
                    'code' => 'X',
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $employee->id,
            'submitted_code' => 'X',
        ]);
        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 0,
        ]);
    }

    public function test_employee_cannot_clear_past_malaysia_holiday(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        Holiday::query()->create([
            'name' => 'Past Malaysia Holiday',
            'date' => '2026-07-23',
            'type' => 'malaysia',
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => '2026-07-23',
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-23',
                    'code' => 'X',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            'You can only un-submit attendance on a Malaysian Public Holiday on the same day. Please contact HR.',
        );
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $employee->id,
            'submitted_code' => 'O',
        ]);
    }

    public function test_admin_daily_update_allows_clear_on_malaysia_holiday(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $leaveTypeR = LeaveType::factory()->create([
            'leave_type_code' => 'R',
            'name' => 'Replacement Leave',
            'is_active' => true,
            'is_quota_based' => true,
        ]);

        Holiday::query()->create([
            'name' => 'Past Malaysia Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => '2026-07-10',
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        UserYearlyLeaveRecord::query()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 1,
            'taken_days' => 0,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/reports/daily/update', [
            'user_id' => $employee->id,
            'date' => '2026-07-10',
            'code' => 'X',
        ])->assertOk();

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $employee->id,
            'submitted_code' => 'X',
        ]);
        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 0,
        ]);
    }

    public function test_admin_update_allows_code_change_on_malaysia_holiday(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $leaveTypeR = LeaveType::factory()->create([
            'leave_type_code' => 'R',
            'name' => 'Replacement Leave',
            'is_active' => true,
            'is_quota_based' => true,
        ]);

        $log = AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => '2026-07-10',
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        Holiday::query()->create([
            'name' => 'Malaysia Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);

        UserYearlyLeaveRecord::query()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 1,
            'taken_days' => 0,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/attendance/{$log->id}", [
            'code' => 'X',
        ])->assertOk();

        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log->id,
            'submitted_code' => 'X',
        ]);
        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 0,
        ]);
    }

    public function test_admin_can_force_reverse_credit_when_already_consumed(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $leaveTypeR = LeaveType::factory()->create([
            'leave_type_code' => 'R',
            'name' => 'Replacement Leave',
            'is_active' => true,
            'is_quota_based' => true,
        ]);

        $log = AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => '2026-07-10',
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        Holiday::query()->create([
            'name' => 'Malaysia Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);

        UserYearlyLeaveRecord::query()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 1,
            'taken_days' => 1,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/attendance/{$log->id}", [
            'code' => 'X',
        ])->assertOk();

        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log->id,
            'submitted_code' => 'X',
        ]);
        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 0,
            'taken_days' => 1,
        ]);

        $record = UserYearlyLeaveRecord::query()
            ->where('user_id', $employee->id)
            ->where('leave_type_id', $leaveTypeR->id)
            ->where('year', 2026)
            ->firstOrFail();

        $this->assertSame(-1.0, (float) $record->assigned_days - (float) $record->taken_days);
    }
}
