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

class ReplacementLeaveBalanceTest extends TestCase
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

    public function test_replacement_balance_counts_malaysia_holidays_worked_minus_r_taken(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployeeWithReplacementLeave();

        // In-window Malaysian holiday + Present → +1 credit.
        Holiday::query()->create([
            'name' => 'Malaysia Day In Window',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);
        $this->logAttendance($employee, '2026-07-10', 'O');

        // Hong Kong holiday worked must NOT credit.
        Holiday::query()->create([
            'name' => 'HK Holiday',
            'date' => '2026-07-12',
            'type' => 'hong_kong',
        ]);
        $this->logAttendance($employee, '2026-07-12', 'O');

        // Malaysian holiday without Present must NOT credit.
        Holiday::query()->create([
            'name' => 'Skipped Malaysia Holiday',
            'date' => '2026-07-15',
            'type' => 'malaysia',
        ]);
        $this->logAttendance($employee, '2026-07-15', 'X');

        // In-window Replacement Leave → −1 debit.
        $this->logAttendance($employee, '2026-07-20', 'R', $leaveTypeR->id);

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/user/replacement-balance');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.holidays_worked', 1);
        $response->assertJsonPath('data.leaves_taken', 1);
        $response->assertJsonPath('data.balance', 0);
        $response->assertJsonPath('data.window_start', '2026-06-25');
        $response->assertJsonPath('data.window_end', '2026-07-24');
        $response->assertJsonPath('data.holidays_worked_dates', ['2026-07-10']);
        $response->assertJsonPath('data.leaves_taken_dates', ['2026-07-20']);
    }

    public function test_fifo_window_drops_expired_holiday_credits_and_aged_leaves(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployeeWithReplacementLeave();

        // Expired credit: Malaysian holiday Present 31 days ago (outside 30-day window).
        Holiday::query()->create([
            'name' => 'Expired Holiday',
            'date' => '2026-06-23',
            'type' => 'malaysia',
        ]);
        $this->logAttendance($employee, '2026-06-23', 'O');

        // Fresh credit still inside the window.
        Holiday::query()->create([
            'name' => 'Fresh Holiday',
            'date' => '2026-07-01',
            'type' => 'malaysia',
        ]);
        $this->logAttendance($employee, '2026-07-01', 'O');

        // Aged R usage outside the window must not reduce balance.
        $this->logAttendance($employee, '2026-06-20', 'R', $leaveTypeR->id);

        // Fresh R usage on/after oldest valid credit does reduce balance.
        $this->logAttendance($employee, '2026-07-05', 'R', $leaveTypeR->id);

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/user/replacement-balance?date=2026-07-24');

        $response->assertOk();
        // Only the fresh holiday credit and R since that credit remain.
        $response->assertJsonPath('data.holidays_worked', 1);
        $response->assertJsonPath('data.leaves_taken', 1);
        $response->assertJsonPath('data.balance', 0);
        $response->assertJsonPath('data.holidays_worked_dates', ['2026-07-01']);
        $response->assertJsonPath('data.leaves_taken_dates', ['2026-07-05']);
        $response->assertJsonPath('data.oldest_valid_credit_date', '2026-07-01');
    }

    public function test_expired_credit_with_orphan_r_in_window_does_not_go_negative(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployeeWithReplacementLeave();

        // Credit earned, then consumed with R, then credit slides out of the window.
        Holiday::query()->create([
            'name' => 'Expired Holiday',
            'date' => '2026-06-23',
            'type' => 'malaysia',
        ]);
        $this->logAttendance($employee, '2026-06-23', 'O');
        // R remains inside the 30-day window (starts 2026-06-25) but its credit expired.
        $this->logAttendance($employee, '2026-06-28', 'R', $leaveTypeR->id);

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/user/replacement-balance?date=2026-07-24');

        $response->assertOk();
        $response->assertJsonPath('data.holidays_worked', 0);
        $response->assertJsonPath('data.leaves_taken', 0);
        $response->assertJsonPath('data.balance', 0);
        $response->assertJsonPath('data.oldest_valid_credit_date', null);
    }

    public function test_r_before_oldest_valid_credit_does_not_consume_current_credits(): void
    {
        [$employee, $leaveTypeR] = $this->seedEmployeeWithReplacementLeave();

        // Orphan R still in the window, but before the oldest valid credit.
        $this->logAttendance($employee, '2026-06-28', 'R', $leaveTypeR->id);

        Holiday::query()->create([
            'name' => 'Fresh Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);
        $this->logAttendance($employee, '2026-07-10', 'O');

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/user/replacement-balance?date=2026-07-24');

        $response->assertOk();
        $response->assertJsonPath('data.holidays_worked', 1);
        $response->assertJsonPath('data.leaves_taken', 0);
        $response->assertJsonPath('data.balance', 1);
        $response->assertJsonPath('data.oldest_valid_credit_date', '2026-07-10');
        $response->assertJsonPath('data.leaves_taken_dates', []);
    }

    public function test_profile_includes_holiday_for_date_and_replacement_leave_summary(): void
    {
        [$employee] = $this->seedEmployeeWithReplacementLeave();

        Holiday::query()->create([
            'name' => 'Today Malaysia Holiday',
            'date' => '2026-07-24',
            'description' => 'National day',
            'type' => 'malaysia',
        ]);

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/profile?date=2026-07-24');

        $response->assertOk();
        $response->assertJsonPath('data.holiday.name', 'Today Malaysia Holiday');
        $response->assertJsonPath('data.holiday.type', 'malaysia');
        $response->assertJsonPath('data.replacement_leave.balance', 0);
        $response->assertJsonPath('data.replacement_leave.window_days', 30);

        $nonHoliday = $this->getJson('/api/profile?date=2026-07-23');
        $nonHoliday->assertOk();
        $nonHoliday->assertJsonPath('data.holiday', null);
    }

    public function test_soft_deleted_malaysian_holiday_does_not_generate_replacement_credit(): void
    {
        [$employee] = $this->seedEmployeeWithReplacementLeave();

        $holiday = Holiday::query()->create([
            'name' => 'Soft Delete Credit Holiday',
            'date' => '2026-07-10',
            'type' => 'malaysia',
        ]);
        $this->logAttendance($employee, '2026-07-10', 'O');

        Sanctum::actingAs($employee);

        $beforeDelete = $this->getJson('/api/user/replacement-balance?date=2026-07-24');
        $beforeDelete->assertOk();
        $beforeDelete->assertJsonPath('data.holidays_worked', 1);
        $beforeDelete->assertJsonPath('data.balance', 1);
        $beforeDelete->assertJsonPath('data.holidays_worked_dates', ['2026-07-10']);

        $holiday->delete();

        $afterDelete = $this->getJson('/api/user/replacement-balance?date=2026-07-24');
        $afterDelete->assertOk();
        $afterDelete->assertJsonPath('data.holidays_worked', 0);
        $afterDelete->assertJsonPath('data.balance', 0);
        $afterDelete->assertJsonPath('data.holidays_worked_dates', []);
        $afterDelete->assertJsonPath('data.oldest_valid_credit_date', null);
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

    private function logAttendance(User $user, string $date, string $code, ?int $leaveTypeId = null): void
    {
        AttendanceLog::query()->create([
            'user_id' => $user->id,
            'team_id' => $user->team_id,
            'date' => $date,
            'submitted_code' => $code,
            'leave_type_id' => $leaveTypeId,
        ]);
    }
}
