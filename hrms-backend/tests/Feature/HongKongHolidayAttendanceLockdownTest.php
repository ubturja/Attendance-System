<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HongKongHolidayAttendanceLockdownTest extends TestCase
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

    public function test_store_rejects_attendance_on_hong_kong_holiday_today(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        Holiday::query()->create([
            'name' => 'HK Public Holiday',
            'date' => '2026-07-24',
            'type' => 'hong_kong',
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

        $response->assertForbidden();
        $response->assertJson([
            'success' => false,
            'message' => 'Attendance submission is disabled for Hong Kong public holidays.',
        ]);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_store_allows_attendance_on_malaysia_holiday_today(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

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
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_admin_daily_update_allows_past_hong_kong_holiday_date(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        Holiday::query()->create([
            'name' => 'Past HK Holiday',
            'date' => '2026-07-10',
            'type' => 'hong_kong',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/reports/daily/update', [
            'user_id' => $employee->id,
            'date' => '2026-07-10',
            'code' => 'O',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $employee->id,
            'submitted_code' => 'O',
        ]);
    }

    public function test_admin_update_existing_log_allows_hong_kong_holiday_date(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        // Pre-seeded log on a date that is later classified as a HK holiday.
        $log = AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => '2026-07-10',
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        Holiday::query()->create([
            'name' => 'HK Holiday',
            'date' => '2026-07-10',
            'type' => 'hong_kong',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/attendance/{$log->id}", [
            'code' => 'X',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log->id,
            'submitted_code' => 'X',
        ]);
    }

    public function test_soft_deleted_hong_kong_holiday_still_blocks_employee_writes(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        $holiday = Holiday::query()->create([
            'name' => 'Archived HK Holiday',
            'date' => '2026-07-24',
            'type' => 'hong_kong',
        ]);
        $holiday->delete();

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

        $response->assertForbidden();
        $response->assertJson([
            'success' => false,
            'message' => 'Attendance submission is disabled for Hong Kong public holidays.',
        ]);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_admin_daily_update_allows_soft_deleted_hong_kong_holiday_date(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        $holiday = Holiday::query()->create([
            'name' => 'Archived HK Holiday',
            'date' => '2026-07-10',
            'type' => 'hong_kong',
        ]);
        $holiday->delete();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/reports/daily/update', [
            'user_id' => $employee->id,
            'date' => '2026-07-10',
            'code' => 'O',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $employee->id,
            'submitted_code' => 'O',
        ]);
    }
}
