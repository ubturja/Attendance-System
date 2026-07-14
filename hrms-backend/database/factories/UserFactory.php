<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'job_title' => 'Employee',
            'nationality' => null,
            'passport_number' => strtoupper(Str::random(12)),
            'phone_number' => null,
            'address' => null,
            'work_type' => null,
            'is_active' => true,
            'team_id' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'job_title' => 'Admin',
        ]);
    }

    public function employee(): static
    {
        return $this->state(fn (array $attributes): array => [
            'job_title' => 'Employee',
        ]);
    }

    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => $team->id,
        ]);
    }
}
