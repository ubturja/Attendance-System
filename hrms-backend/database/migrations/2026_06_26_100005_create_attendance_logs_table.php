<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD Table: attendance_logs (Dependency Order: 5 of 5)
 *
 * Immutable daily attendance facts for pivot reporting (daily / monthly / yearly).
 * Stores the team context at log time for historical accuracy when users change teams.
 * Non-leave codes (W, O, X) persist with leave_type_id = NULL per business rules.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            // Primary key — BIGINT UNSIGNED AUTO_INCREMENT per ERD.
            $table->id();

            // Employee whose attendance is recorded.
            // restrictOnDelete: preserve audit trail; users with logs cannot be hard-deleted.
            $table->unsignedBigInteger('user_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            // Team snapshot at time of entry — ERD: preserves history after team reassignment.
            // restrictOnDelete: teams referenced by logs cannot be removed (aligns with Admin rules).
            $table->unsignedBigInteger('team_id');

            $table->foreign('team_id')
                ->references('id')
                ->on('teams')
                ->restrictOnDelete();

            // Calendar date of the attendance event (one row per user per day at application layer).
            $table->date('date');

            // Nullable FK to leave_types — NULL for non-leave codes W (WFH), O (Office), X (OFF/CC).
            // nullOnDelete: if a leave type is retired, historical rows remain with NULL reference.
            $table->unsignedBigInteger('leave_type_id')->nullable();

            $table->foreign('leave_type_id')
                ->references('id')
                ->on('leave_types')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
