<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds production Admin accounts for initial HRMS access.
 *
 * Uses updateOrCreate on email so re-running the seeder is idempotent.
 */
class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin1@example.com'],
            [
                'name' => 'HR Admin 1',
                'password' => Hash::make('admin123'),
                'job_title' => 'Admin',
                'passport_number' => 'ADMIN-PASSPORT-001',
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin2@example.com'],
            [
                'name' => 'HR Admin 2',
                'password' => Hash::make('admin123'),
                'job_title' => 'Admin',
                'passport_number' => 'ADMIN-PASSPORT-002',
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin3@example.com'],
            [
                'name' => 'HR Admin 3',
                'password' => Hash::make('admin123'),
                'job_title' => 'Admin',
                'passport_number' => 'ADMIN-PASSPORT-003',
                'is_active' => true,
            ]
        );
    }
}
