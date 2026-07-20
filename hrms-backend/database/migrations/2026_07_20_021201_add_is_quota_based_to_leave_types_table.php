<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes countable leave quotas (Annual, Sick) from attendance-only
 * statuses (Work from Home). Default true keeps existing standard leaves intact.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('is_quota_based')->default(true);
        });

        // Preserve existing WFH / non-quota flags from requires_allocation when present.
        if (Schema::hasColumn('leave_types', 'requires_allocation')) {
            DB::table('leave_types')->update([
                'is_quota_based' => DB::raw('requires_allocation'),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('is_quota_based');
        });
    }
};
