<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\User;
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

    public function test_store_rejects_non_present_code_on_malaysia_holiday(): void
    {
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        LeaveType::factory()->create([
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

        foreach (['X', 'R', 'A'] as $code) {
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

    public function test_admin_daily_update_rejects_non_present_on_malaysia_holiday(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        Holiday::query()->create([
            'name' => 'Past Malaysia Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/reports/daily/update', [
            'user_id' => $employee->id,
            'date' => '2026-07-10',
            'code' => 'X',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            'Only "Present" can be submitted on a Malaysian Public Holiday.',
        );
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_admin_update_rejects_non_present_on_malaysia_holiday(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->forTeam($team)->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

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

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/attendance/{$log->id}", [
            'code' => 'R',
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
    }
}
