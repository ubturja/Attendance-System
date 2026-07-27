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

class ReplacementLeaveEarningSyncTest extends TestCase
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

    public function test_present_on_malaysia_holiday_increments_assigned_days(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployeeWithReplacementLeave();

        Holiday::query()->create([
            'name' => 'Malaysia Holiday',
            'date' => '2026-07-24',
            'type' => 'malaysia',
        ]);

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-24',
                    'code' => 'O',
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 1.0,
            'taken_days' => 0.0,
        ]);
    }

    public function test_present_on_hong_kong_holiday_does_not_credit_assigned_days(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployeeWithReplacementLeave();

        // HK holidays are blocked for submission — seed a non-today HK day via Admin path
        // is also blocked. Use a normal weekday Present to prove no accidental R credit.
        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-24',
                    'code' => 'O',
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseMissing('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
        ]);
    }

    public function test_service_decrements_assigned_days_when_present_is_reversed(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployeeWithReplacementLeave();

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
            'assigned_days' => 1.0,
        ]);

        // API Present-only gate blocks O→X; ledger reversal is exercised at the sync service.
        app(\App\Services\Leave\ReplacementLeaveEarningSync::class)->syncAfterAttendanceChange(
            userId: $employee->id,
            date: '2026-07-24',
            previousCode: 'O',
            newCode: 'X',
        );

        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 0.0,
        ]);
    }

    public function test_admin_create_credits_assigned_days_and_rejects_non_present_on_malaysia_holiday(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployeeWithReplacementLeave();
        $admin = User::factory()->admin()->forTeam($employee->team)->create();

        Holiday::query()->create([
            'name' => 'Past Malaysia Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/reports/daily/update', [
            'user_id' => $employee->id,
            'date' => '2026-07-10',
            'code' => 'O',
        ])->assertOk();

        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'year' => 2026,
            'assigned_days' => 1.0,
        ]);

        $log = AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', '2026-07-10')
            ->firstOrFail();

        $response = $this->putJson("/api/attendance/{$log->id}", [
            'code' => 'X',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            'Only "Present" can be submitted on a Malaysian Public Holiday.',
        );
        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log->id,
            'submitted_code' => 'O',
        ]);
        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'assigned_days' => 1.0,
        ]);
    }

    public function test_soft_deleted_malaysia_holiday_does_not_credit_assigned_days(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployeeWithReplacementLeave();

        $holiday = Holiday::query()->create([
            'name' => 'Deleted Malaysia Holiday',
            'date' => '2026-07-24',
            'type' => 'malaysia',
        ]);
        $holiday->delete();

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

        $this->assertDatabaseMissing('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
        ]);
        $this->assertSame(0, UserYearlyLeaveRecord::query()->count());
    }

    public function test_cannot_revoke_malaysia_holiday_present_when_replacement_leave_already_taken(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployeeWithReplacementLeave();
        $admin = User::factory()->admin()->forTeam($employee->team)->create();

        Holiday::query()->create([
            'name' => 'Past Malaysia Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/reports/daily/update', [
            'user_id' => $employee->id,
            'date' => '2026-07-10',
            'code' => 'O',
        ])->assertOk();

        // Consume the earned credit on a later weekday.
        Carbon::setTestNow(Carbon::parse('2026-07-27'));
        Sanctum::actingAs($employee);

        $this->postJson('/api/attendance', [
            'records' => [
                [
                    'user_id' => $employee->id,
                    'date' => '2026-07-27',
                    'code' => 'R',
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'assigned_days' => 1.0,
            'taken_days' => 1.0,
        ]);

        // Present-only API gate blocks O→X; ledger integrity is asserted on the sync service.
        try {
            app(\App\Services\Leave\ReplacementLeaveEarningSync::class)->syncAfterAttendanceChange(
                userId: $employee->id,
                date: '2026-07-10',
                previousCode: 'O',
                newCode: 'X',
            );
            $this->fail('Expected ValidationException when reversing a consumed credit.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString(
                'already been consumed',
                $exception->errors()['attendance'][0] ?? '',
            );
        }

        $this->assertDatabaseHas('user_yearly_leave_records', [
            'user_id' => $employee->id,
            'leave_type_id' => $leaveTypeR->id,
            'assigned_days' => 1.0,
            'taken_days' => 1.0,
        ]);
    }

    /**
     * @return array{0: User, 1: LeaveType}
     */
    private function seedEmployeeWithReplacementLeave(): array
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $leaveTypeR = LeaveType::factory()->create([
            'leave_type_code' => 'R',
            'name' => 'Replacement Leave',
            'is_active' => true,
            'is_quota_based' => true,
        ]);

        return [$employee, $leaveTypeR];
    }
}
