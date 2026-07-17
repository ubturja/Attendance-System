<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LeaveType;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserLeaveBalancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_user_returns_all_active_leave_types_with_zero_defaults(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();
        $currentYear = (int) now()->year;

        $annualLeave = LeaveType::factory()->annual()->create(['is_active' => true]);
        $sickLeave = LeaveType::factory()->create([
            'leave_type_code' => 'S',
            'name' => 'Sick Leave',
            'is_active' => true,
        ]);
        LeaveType::factory()->create([
            'leave_type_code' => 'X',
            'name' => 'Inactive Leave',
            'is_active' => false,
        ]);

        UserYearlyLeaveRecord::factory()->create([
            'user_id' => $employee->id,
            'leave_type_id' => $annualLeave->id,
            'year' => $currentYear,
            'assigned_days' => 14.0,
            'taken_days' => 2.5,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/users/{$employee->id}");

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $records = $response->json('data.yearly_leave_records');
        $this->assertCount(2, $records);

        $annual = collect($records)->firstWhere('leave_type_id', $annualLeave->id);
        $this->assertSame(14.0, (float) $annual['assigned_days']);
        $this->assertSame(2.5, (float) $annual['taken_days']);
        $this->assertSame(11.5, (float) $annual['remaining_days']);
        $this->assertSame('Annual Leave', $annual['leave_type']['name']);
        $this->assertSame('A', $annual['leave_type']['leave_type_code']);

        $sick = collect($records)->firstWhere('leave_type_id', $sickLeave->id);
        $this->assertSame(0.0, (float) $sick['assigned_days']);
        $this->assertSame(0.0, (float) $sick['taken_days']);
        $this->assertSame(0.0, (float) $sick['remaining_days']);
        $this->assertSame('Sick Leave', $sick['leave_type']['name']);
        $this->assertSame('S', $sick['leave_type']['leave_type_code']);
    }
}
