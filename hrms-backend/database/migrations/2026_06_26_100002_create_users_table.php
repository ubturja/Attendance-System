<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD Table: users (Dependency Order: 2 of 5)
 *
 * Core identity table for Admin and Employee accounts. Depends on `teams`
 * (for `team_id`). After creation, applies the deferred `teams.team_leader_id`
 * foreign key to complete the bidirectional teams ↔ users relationship.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // Primary key — BIGINT UNSIGNED AUTO_INCREMENT per ERD.
            $table->id();

            // Display / legal name for profile and reporting surfaces.
            $table->string('name', 255);

            // Login identifier; VARCHAR(191) for utf8mb4-compatible unique indexing.
            $table->string('email', 191)->unique();

            // Bcrypt-hashed credential; never store plaintext (Sanctum auth layer).
            $table->string('password', 255);

            // RBAC discriminator consumed by CheckRole middleware (Admin vs Employee).
            $table->enum('job_title', ['Admin', 'Employee']);

            // Optional demographic field; TEXT allows multi-word nationality values.
            $table->text('nationality')->nullable();

            // Immutable business identifier after account creation (PRD); globally unique.
            $table->string('passport_number', 100)->unique();

            // Optional contact number for HR records.
            $table->string('phone_number', 50)->nullable();

            // Optional free-form postal / residential address.
            $table->text('address')->nullable();

            // Admin-managed employment classification (e.g., full-time, contract).
            $table->string('work_type', 100)->nullable();

            // Soft operational flag; inactive users retain historical attendance rows.
            $table->boolean('is_active')->default(true);

            // Nullable FK to teams — ERD: "Nullable on delete" when a team is removed.
            $table->unsignedBigInteger('team_id')->nullable();

            $table->foreign('team_id')
                ->references('id')
                ->on('teams')
                ->nullOnDelete();
        });

        // Deferred FK: teams.team_leader_id → users.id (1:1 leader assignment per ERD).
        // nullOnDelete: removing the leader user clears the reference; the team row persists.
        Schema::table('teams', function (Blueprint $table) {
            $table->foreign('team_leader_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['team_leader_id']);
        });

        Schema::dropIfExists('users');
    }
};
