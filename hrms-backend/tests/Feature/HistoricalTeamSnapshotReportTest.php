<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HistoricalTeamSnapshotReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_report_includes_user_by_historical_attendance_team_and_displays_snapshot_name(): void
    {
        $admin = User::factory()->admin()->create();
        $teamAlpha = Team::factory()->create(['team_name' => 'Alpha Team']);
        $teamBeta = Team::factory()->create(['team_name' => 'Beta Team']);

        // Currently on Beta, but logged attendance while on Alpha for the report date.
        $employee = User::factory()->employee()->forTeam($teamBeta)->create([
            'name' => 'Historical Snapshot User',
        ]);

        $reportDate = '2026-07-15';

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $teamAlpha->id,
            'date' => $reportDate,
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/reports/daily?date={$reportDate}&team_id={$teamAlpha->id}");

        $response->assertOk();
        $response->assertJsonPath('data.date', $reportDate);

        $records = $response->json('data.records');
        $this->assertIsArray($records);

        $matched = collect($records)->firstWhere('user_id', $employee->id);

        $this->assertNotNull($matched, 'Employee with Alpha attendance snapshot must appear when filtering by Alpha.');
        $this->assertSame($teamAlpha->id, $matched['team_id']);
        $this->assertSame('Alpha Team', $matched['team_name']);
    }

    public function test_monthly_report_includes_transferred_user_via_attendance_team_snapshot(): void
    {
        $admin = User::factory()->admin()->create();
        $teamAlpha = Team::factory()->create(['team_name' => 'Alpha Team']);
        $teamBeta = Team::factory()->create(['team_name' => 'Beta Team']);

        $employee = User::factory()->employee()->forTeam($teamBeta)->create([
            'name' => 'Monthly Snapshot User',
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $teamAlpha->id,
            'date' => '2026-07-10',
            'submitted_code' => 'W',
            'leave_type_id' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/reports/monthly?year=2026&month=7&team_id={$teamAlpha->id}");

        $response->assertOk();

        $rows = $response->json('data.rows');
        $this->assertIsArray($rows);

        $matched = collect($rows)->firstWhere('user_id', $employee->id);

        $this->assertNotNull($matched, 'Transferred employee must remain visible under historical Alpha filter.');
        $this->assertSame('Alpha Team', $matched['team_name']);
    }

    public function test_yearly_report_uses_historical_team_name_from_attendance_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $teamAlpha = Team::factory()->create(['team_name' => 'Alpha Team']);
        $teamBeta = Team::factory()->create(['team_name' => 'Beta Team']);

        $employee = User::factory()->employee()->forTeam($teamBeta)->create([
            'name' => 'Yearly Snapshot User',
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $teamAlpha->id,
            'date' => '2026-03-12',
            'submitted_code' => 'O',
            'leave_type_id' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/reports/yearly?year=2026&team_id={$teamAlpha->id}");

        $response->assertOk();

        $rows = $response->json('data.rows');
        $this->assertIsArray($rows);

        $matched = collect($rows)->firstWhere('user_id', $employee->id);

        $this->assertNotNull($matched);
        $this->assertSame('Alpha Team', $matched['team_name']);
    }
}
