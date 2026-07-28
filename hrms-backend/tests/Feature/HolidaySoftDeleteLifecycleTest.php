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

class HolidaySoftDeleteLifecycleTest extends TestCase
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

    public function test_can_delete_malaysia_holiday_when_attendance_exists(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        $holiday = Holiday::query()->create([
            'name' => 'Malaysia Holiday',
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

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/holidays/{$holiday->id}")->assertOk();

        $this->assertSoftDeleted('holidays', ['id' => $holiday->id]);
    }

    public function test_can_delete_malaysia_holiday_when_no_attendance_exists(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();

        $holiday = Holiday::query()->create([
            'name' => 'Unused Malaysia Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/holidays/{$holiday->id}")->assertOk();

        $this->assertSoftDeleted('holidays', ['id' => $holiday->id]);
    }

    public function test_can_delete_hong_kong_holiday_even_when_attendance_exists(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        // Legacy attendance may exist before a date was classified as HK.
        $holiday = Holiday::query()->create([
            'name' => 'HK Holiday',
            'date' => '2026-07-10',
            'type' => 'hong_kong',
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => '2026-07-10',
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/holidays/{$holiday->id}")->assertOk();

        $this->assertSoftDeleted('holidays', ['id' => $holiday->id]);
    }
}
