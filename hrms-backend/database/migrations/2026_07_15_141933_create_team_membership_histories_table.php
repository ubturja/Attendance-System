<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit table: team_membership_histories
 *
 * Records when a user joined or left a team so membership changes remain
 * queryable after reassignment or team soft-deletion.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('team_membership_histories', function (Blueprint $table) {
            $table->id();

            // Team the membership period belongs to.
            // restrictOnDelete: preserve audit trail; teams with history cannot be hard-deleted.
            $table->unsignedBigInteger('team_id');

            $table->foreign('team_id')
                ->references('id')
                ->on('teams')
                ->restrictOnDelete();

            // User who was a member for this period.
            // restrictOnDelete: preserve audit trail; users with history cannot be hard-deleted.
            $table->unsignedBigInteger('user_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            // Inclusive membership window — null left_at means membership is still open.
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_membership_histories');
    }
};
