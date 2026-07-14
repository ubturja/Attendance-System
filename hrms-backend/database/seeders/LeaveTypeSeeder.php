<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * Seeds the core leave types from the PRD / HighLevelArchitecture.
 *
 * Includes Work from Home (`W`) so Admins can toggle it in Leave Types UI.
 * Attendance still treats `W` as a non-leave / zero-deduction code in
 * AttendanceVariantMapper (leave_type_id NULL, no balance check).
 * `W` also sets requires_allocation = false so it is excluded from yearly quotas.
 *
 * Intentionally excludes:
 * - Fractional variants (AO, OA, NO, ON) — frontend mapping only
 * - Non-leave codes O and X — hardcoded frontend base options
 *
 * ERD: leave_types has no created_at / updated_at columns.
 */
class LeaveTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $leaveTypes = [
            [
                'leave_type_code' => 'A',
                'name' => 'Annual Leave',
                'is_active' => true,
                'requires_allocation' => true,
            ],
            [
                'leave_type_code' => 'S',
                'name' => 'Sickness Leave',
                'is_active' => true,
                'requires_allocation' => true,
            ],
            [
                'leave_type_code' => 'M',
                'name' => 'Maternity or Paternity',
                'is_active' => true,
                'requires_allocation' => true,
            ],
            [
                'leave_type_code' => 'R',
                'name' => 'Replacement Leave',
                'is_active' => true,
                'requires_allocation' => true,
            ],
            [
                'leave_type_code' => 'B',
                'name' => 'Birthday Leave',
                'is_active' => true,
                'requires_allocation' => true,
            ],
            [
                'leave_type_code' => 'H',
                'name' => 'Hospitalization Leave',
                'is_active' => true,
                'requires_allocation' => true,
            ],
            [
                'leave_type_code' => 'C',
                'name' => 'Compassionate Leave',
                'is_active' => true,
                'requires_allocation' => true,
            ],
            [
                'leave_type_code' => 'N',
                'name' => 'No Pay Leave',
                'is_active' => true,
                'requires_allocation' => true,
            ],
            [
                'leave_type_code' => 'MRG',
                'name' => 'Marriage Leave',
                'is_active' => true,
                'requires_allocation' => true,
            ],
            [
                'leave_type_code' => 'W',
                'name' => 'Work from Home',
                'is_active' => true,
                'requires_allocation' => false,
            ],
        ];

        // Idempotent: unique on leave_type_code; updates name/flags on re-seed.
        LeaveType::query()->upsert(
            $leaveTypes,
            ['leave_type_code'],
            ['name', 'is_active', 'requires_allocation'],
        );
    }
}
