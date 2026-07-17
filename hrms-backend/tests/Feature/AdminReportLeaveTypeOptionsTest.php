<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReportLeaveTypeOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_all_status_catalog_includes_active_inactive_and_archived_leave_types(): void
    {
        $admin = User::factory()->admin()->create();
        $active = LeaveType::factory()->create([
            'leave_type_code' => 'ACT',
            'name' => 'Active Leave',
            'is_active' => true,
        ]);
        $inactive = LeaveType::factory()->create([
            'leave_type_code' => 'INA',
            'name' => 'Inactive Leave',
            'is_active' => false,
        ]);
        $archived = LeaveType::factory()->create([
            'leave_type_code' => 'ARC',
            'name' => 'Archived Leave',
            'is_active' => false,
        ]);
        $archived->delete();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/leave-types?status=all');

        $response->assertOk();
        $response->assertJsonPath('message', 'All leave types retrieved successfully.');

        $typesById = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($typesById->has($active->id));
        $this->assertTrue($typesById->has($inactive->id));
        $this->assertTrue($typesById->has($archived->id));
        $this->assertFalse((bool) $typesById->get($inactive->id)['is_active']);
        $this->assertNotNull($typesById->get($archived->id)['deleted_at']);
    }

    public function test_employee_leave_type_endpoint_remains_active_and_non_archived_only(): void
    {
        $employee = User::factory()->employee()->create();
        $active = LeaveType::factory()->create([
            'leave_type_code' => 'ACT',
            'is_active' => true,
        ]);
        $inactive = LeaveType::factory()->create([
            'leave_type_code' => 'INA',
            'is_active' => false,
        ]);
        $archived = LeaveType::factory()->create([
            'leave_type_code' => 'ARC',
            'is_active' => true,
        ]);
        $archived->delete();

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/leave-types');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($archived->id));
    }
}
