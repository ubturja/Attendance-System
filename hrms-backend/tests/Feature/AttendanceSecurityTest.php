<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_guest_cannot_access_admin_leave_types_endpoint(): void
    {
        $response = $this->getJson('/api/admin/leave-types');

        $response->assertUnauthorized();
        $response->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }

    public function test_authenticated_employee_is_forbidden_from_admin_leave_types_endpoint(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/admin/leave-types');

        $response->assertForbidden();
        $response->assertJson([
            'success' => false,
            'message' => 'Forbidden. Insufficient role privileges.',
        ]);
    }

    public function test_employee_cannot_submit_attendance_for_user_outside_their_team(): void
    {
        $teamAlpha = Team::factory()->create(['team_name' => 'Alpha Team']);
        $teamBeta = Team::factory()->create(['team_name' => 'Beta Team']);

        $submitter = User::factory()->employee()->forTeam($teamAlpha)->create();
        $outsider = User::factory()->employee()->forTeam($teamBeta)->create();

        Sanctum::actingAs($submitter);

        $date = now()->toDateString();

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $outsider->id,
                    'date' => $date,
                    'code' => 'O',
                ],
            ],
        ]);

        $response->assertForbidden();
        $response->assertJson([
            'success' => false,
            'message' => 'Forbidden. You may only submit attendance for members of your assigned team.',
            'errors' => [
                'user_id' => $outsider->id,
            ],
        ]);

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_attendance_submission_for_non_today_date_is_forbidden(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => now()->subDay()->toDateString(),
                    'code' => 'O',
                ],
            ],
        ]);

        $response->assertForbidden();
        $response->assertJson([
            'success' => false,
            'message' => 'You can only submit or update attendance for today. Contact your Admin for past changes.',
        ]);

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_attendance_submission_rejects_leave_overdraft_and_rolls_back_transaction(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $annualLeave = LeaveType::factory()->annual()->create();

        $balance = UserYearlyLeaveRecord::factory()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $annualLeave->id,
            'year' => (int) date('Y'),
            'assigned_days' => 0.00,
            'taken_days' => 0.00,
        ]);

        Sanctum::actingAs($employee);

        $date = now()->toDateString();

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => $date,
                    'code' => 'A',
                ],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJson([
            'success' => false,
        ]);
        $response->assertJsonPath('errors.user_id', $employee->id);
        $response->assertJsonPath('errors.leave_type_code', 'A');

        $this->assertDatabaseCount('attendance_logs', 0);

        $balance->refresh();
        $this->assertSame(0.0, (float) $balance->taken_days);
        $this->assertSame(0.0, $balance->remaining_days);
    }

    public function test_duplicate_attendance_for_same_user_and_date_is_rejected(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        Sanctum::actingAs($employee);

        $date = now()->toDateString();

        $payload = [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => $date,
                    'code' => 'O',
                ],
            ],
        ];

        $firstResponse = $this->postJson('/api/attendance', $payload);

        $firstResponse->assertCreated();
        $this->assertDatabaseCount('attendance_logs', 1);

        $duplicateResponse = $this->postJson('/api/attendance', $payload);

        $duplicateResponse->assertUnprocessable();
        $duplicateResponse->assertJson([
            'success' => false,
            'message' => 'Attendance already logged for this date.',
            'errors' => [
                'user_id' => $employee->id,
                'date' => $date,
            ],
        ]);

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertSame(
            1,
            AttendanceLog::query()
                ->where('user_id', $employee->id)
                ->whereDate('date', $date)
                ->count(),
        );
    }

    public function test_admin_can_override_attendance_code_and_adjust_balance(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $annualLeave = LeaveType::factory()->annual()->create();

        $balance = UserYearlyLeaveRecord::factory()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $annualLeave->id,
            'year' => (int) date('Y'),
            'assigned_days' => 10.00,
            'taken_days' => 1.00,
        ]);

        $date = now()->toDateString();

        $log = AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => $date,
            'submitted_code' => 'A',
            'leave_type_id' => $annualLeave->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/attendance/{$log->id}", [
            'code' => 'AO',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Attendance record updated successfully.',
            'data' => [
                'id' => $log->id,
                'submitted_code' => 'AO',
            ],
        ]);

        $log->refresh();
        $this->assertSame('AO', $log->submitted_code);
        $this->assertSame($annualLeave->id, $log->leave_type_id);

        $balance->refresh();
        $this->assertSame(0.5, (float) $balance->taken_days);
        $this->assertSame(9.5, (float) $balance->remaining_days);
    }

    public function test_weekend_leave_is_logged_without_charging_leave_balance(): void
    {
        // Freeze "today" on Saturday so the dashboard today-only guard still allows submit.
        Carbon::setTestNow(Carbon::parse('2026-07-18'));

        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $annualLeave = LeaveType::factory()->annual()->create();

        $balance = UserYearlyLeaveRecord::factory()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $annualLeave->id,
            'year' => 2026,
            'assigned_days' => 10.00,
            'taken_days' => 2.00,
        ]);

        // Saturday 2026-07-18 — must not drain ledger.
        $weekendDate = '2026-07-18';

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => $weekendDate,
                    'code' => 'A',
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertTrue(
            AttendanceLog::query()
                ->where('user_id', $employee->id)
                ->whereDate('date', $weekendDate)
                ->where('submitted_code', 'A')
                ->where('leave_type_id', $annualLeave->id)
                ->exists(),
        );

        $balance->refresh();
        $this->assertSame(2.0, (float) $balance->taken_days);
        $this->assertSame(8.0, (float) $balance->remaining_days);

        Carbon::setTestNow();
    }

    public function test_weekday_leave_still_charges_leave_balance(): void
    {
        // Freeze "today" on Monday so the dashboard today-only guard still allows submit.
        Carbon::setTestNow(Carbon::parse('2026-07-20'));

        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $annualLeave = LeaveType::factory()->annual()->create();

        $balance = UserYearlyLeaveRecord::factory()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $annualLeave->id,
            'year' => 2026,
            'assigned_days' => 10.00,
            'taken_days' => 2.00,
        ]);

        // Monday 2026-07-20 — full-day leave must charge 1.0.
        $weekdayDate = '2026-07-20';

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => $weekdayDate,
                    'code' => 'A',
                ],
            ],
        ]);

        $response->assertCreated();

        $balance->refresh();
        $this->assertSame(3.0, (float) $balance->taken_days);
        $this->assertSame(7.0, (float) $balance->remaining_days);

        Carbon::setTestNow();
    }

    public function test_admin_weekend_upsert_does_not_charge_leave_balance(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $sickLeave = LeaveType::factory()->create([
            'leave_type_code' => 'S',
            'name' => 'Sick Leave',
        ]);

        $balance = UserYearlyLeaveRecord::factory()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $sickLeave->id,
            'year' => 2026,
            'assigned_days' => 14.00,
            'taken_days' => 1.00,
        ]);

        // Sunday 2026-07-19
        $weekendDate = '2026-07-19';

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/reports/daily/update', [
            'user_id' => $employee->id,
            'date' => $weekendDate,
            'code' => 'S',
        ]);

        $response->assertOk();
        $this->assertTrue(
            AttendanceLog::query()
                ->where('user_id', $employee->id)
                ->whereDate('date', $weekendDate)
                ->where('submitted_code', 'S')
                ->where('leave_type_id', $sickLeave->id)
                ->exists(),
        );

        $balance->refresh();
        $this->assertSame(1.0, (float) $balance->taken_days);
    }

    public function test_employee_cannot_override_attendance_code(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        $log = AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => now()->toDateString(),
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        Sanctum::actingAs($employee);

        $response = $this->putJson("/api/attendance/{$log->id}", [
            'code' => 'A',
        ]);

        $response->assertForbidden();
        $response->assertJson([
            'success' => false,
            'message' => 'Forbidden. Insufficient role privileges.',
        ]);
    }
}
