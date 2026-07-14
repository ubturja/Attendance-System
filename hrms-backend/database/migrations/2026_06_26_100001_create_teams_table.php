<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD Table: teams (Dependency Order: 1 of 5)
 *
 * Creates the organizational unit table used to group employees and attribute
 * attendance logs to a team context. Must run before `users` because
 * `users.team_id` references `teams.id`.
 *
 * Circular FK note: `team_leader_id` references `users.id`, but the `users`
 * table does not exist yet at this step. The column is created here as an
 * unsigned bigint placeholder; the foreign key constraint is applied in the
 * `create_users_table` migration once both tables exist.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            // Primary key — BIGINT UNSIGNED AUTO_INCREMENT per ERD.
            $table->id();

            // Unique team label (VARCHAR 191) for RBAC grouping and report sorting.
            // Length 191 aligns with MySQL utf8mb4 unique-index byte limits.
            $table->string('team_name', 191)->unique();

            // Optional reference to the user designated as team leader (status role).
            // FK constraint deferred until `users` exists — see create_users_table migration.
            $table->unsignedBigInteger('team_leader_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
