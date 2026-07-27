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

class HolidayUpdateMutationGuardTest extends TestCase
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

    public function test_cannot_change_holiday_date_when_attendance_exists(): void
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

        $response = $this->putJson("/api/holidays/{$holiday->id}", [
            'date' => '2026-07-11',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            'Cannot change the date or type of a holiday that already has attendance records. Please use the Daily Report to clear the attendance first.',
        );
        $this->assertSame('2026-07-10', $holiday->fresh()->date->toDateString());
    }

    public function test_cannot_change_holiday_type_when_attendance_exists(): void
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

        $response = $this->putJson("/api/holidays/{$holiday->id}", [
            'type' => 'hong_kong',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            'Cannot change the date or type of a holiday that already has attendance records. Please use the Daily Report to clear the attendance first.',
        );
        $this->assertSame('malaysia', $holiday->fresh()->type);
    }

    public function test_can_rename_holiday_when_attendance_exists(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        $holiday = Holiday::query()->create([
            'name' => 'Old Name',
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

        $this->putJson("/api/holidays/{$holiday->id}", [
            'name' => 'New Name',
        ])->assertOk();

        $fresh = $holiday->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('2026-07-10', $fresh->date->toDateString());
        $this->assertSame('malaysia', $fresh->type);
    }

    public function test_can_change_holiday_date_when_no_attendance_exists(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();

        $holiday = Holiday::query()->create([
            'name' => 'Unused Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/holidays/{$holiday->id}", [
            'date' => '2026-07-11',
            'type' => 'hong_kong',
        ])->assertOk();

        $fresh = $holiday->fresh();
        $this->assertSame('2026-07-11', $fresh->date->toDateString());
        $this->assertSame('hong_kong', $fresh->type);
    }
}
