<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD Table: leave_types (Dependency Order: 3 of 5)
 *
 * Admin-managed catalog of leave categories (e.g., A = Annual, S = Sick, N = No Pay).
 * Drives dynamic attendance dropdowns (`GET /api/leave-types`) and balance tracking
 * in `user_yearly_leave_records`. Must exist before allocation and attendance tables.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            // Primary key — BIGINT UNSIGNED AUTO_INCREMENT per ERD.
            $table->id();

            // Short code submitted by the frontend (e.g., A, S, M, N, AO parent = A).
            // VARCHAR(50) with unique constraint prevents duplicate code collisions.
            $table->string('leave_type_code', 50)->unique();

            // Human-readable label shown in Admin UI and reports (e.g., "Annual Leave").
            $table->string('name', 191);

            // Toggle for dynamic dropdown visibility without deleting historical references.
            // Inactive types are excluded from `WHERE is_active = true` API queries.
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
