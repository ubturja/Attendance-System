<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD Table: user_yearly_leave_records (Dependency Order: 4 of 5)
 *
 * Normalized yearly leave balance ledger: one row per user, leave type, and calendar year.
 * Supports fractional half-day deductions (DECIMAL 8,2). `remaining_days` is intentionally
 * NOT stored — the API computes (assigned_days - taken_days) at runtime per SystemArchitecture.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_yearly_leave_records', function (Blueprint $table) {
            // Primary key — BIGINT UNSIGNED AUTO_INCREMENT per ERD.
            $table->id();

            // Owner of the balance row; cascadeOnDelete removes allocations with the user account.
            $table->unsignedBigInteger('user_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            // Leave category this balance applies to (Annual, Sick, No Pay, etc.).
            // restrictOnDelete: cannot delete a leave_type that still has allocated balances.
            $table->unsignedBigInteger('leave_type_id');

            $table->foreign('leave_type_id')
                ->references('id')
                ->on('leave_types')
                ->restrictOnDelete();

            // Calendar year boundary for the allocation (MySQL YEAR type, e.g., 2026).
            $table->year('year');

            // Admin-assigned quota; DECIMAL(8,2) supports 0.5-day fractional allocations.
            $table->decimal('assigned_days', 8, 2)->default(0);

            // Running total of consumed days; incremented by fractional attendance processing.
            $table->decimal('taken_days', 8, 2)->default(0);

            // Enforces one balance row per (user, leave_type, year) — normalized ERD intent.
            $table->unique(['user_id', 'leave_type_id', 'year'], 'uyr_user_leave_type_year_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_yearly_leave_records');
    }
};
