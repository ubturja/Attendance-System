<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonthlyReportHalfDayTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_report_preserves_half_day_submitted_code_and_fractional_totals(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create([
            'name' => 'Half Day User',
        ]);
        $annualLeave = LeaveType::factory()->annual()->create();

        // Wednesday — counts toward aggregates.
        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => '2026-07-15',
            'submitted_code' => 'AO',
            'leave_type_id' => $annualLeave->id,
        ]);

        // Full-day annual for comparison.
        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => '2026-07-16',
            'submitted_code' => 'A',
            'leave_type_id' => $annualLeave->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/reports/monthly?year=2026&month=7');

        $response->assertOk();

        $row = collect($response->json('data.rows'))->firstWhere('user_id', $employee->id);
        $this->assertNotNull($row);

        // Calendar must show AO, not collapsed parent A.
        $this->assertSame('AO', $row['daily_records'][15] ?? $row['daily_records']['15'] ?? null);
        $this->assertSame('A', $row['daily_records'][16] ?? $row['daily_records']['16'] ?? null);

        // AO = 0.5 annual + 0.5 office; A = 1.0 annual.
        $this->assertSame(1.5, (float) $row['totals']['annual']);
        $this->assertSame(0.0, (float) $row['totals']['sick']);
        $this->assertSame(0.0, (float) $row['totals']['other']);
        $this->assertSame(0.5, (float) $row['total_work_in_office']);
        $this->assertSame(0.0, (float) $row['total_wfh']);
    }

    public function test_monthly_report_counts_no_pay_half_day_as_other_and_office(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();
        $noPay = LeaveType::factory()->create([
            'leave_type_code' => 'N',
            'name' => 'No Pay Leave',
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'date' => '2026-07-17',
            'submitted_code' => 'NO',
            'leave_type_id' => $noPay->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/reports/monthly?year=2026&month=7');

        $response->assertOk();

        $row = collect($response->json('data.rows'))->firstWhere('user_id', $employee->id);
        $this->assertNotNull($row);
        $this->assertSame('NO', $row['daily_records'][17] ?? $row['daily_records']['17'] ?? null);
        $this->assertSame(0.5, (float) $row['totals']['other']);
        $this->assertSame(0.5, (float) $row['total_work_in_office']);
    }
}
