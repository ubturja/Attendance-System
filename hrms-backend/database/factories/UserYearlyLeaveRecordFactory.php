<?php

namespace Database\Factories;

use App\Models\LeaveType;
use App\Models\User;
use App\Models\UserYearlyLeaveRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserYearlyLeaveRecord>
 */
class UserYearlyLeaveRecordFactory extends Factory
{
    protected $model = UserYearlyLeaveRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'leave_type_id' => LeaveType::factory(),
            'year' => (int) date('Y'),
            'assigned_days' => 0.00,
            'taken_days' => 0.00,
        ];
    }
}
