<?php

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leave_type_code' => strtoupper(Str::random(3)),
            'name' => fake()->words(2, true).' Leave',
            'is_active' => true,
        ];
    }

    public function annual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'leave_type_code' => 'A',
            'name' => 'Annual Leave',
        ]);
    }
}
