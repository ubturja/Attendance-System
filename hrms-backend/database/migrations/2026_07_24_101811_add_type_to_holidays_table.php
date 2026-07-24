<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Region classification for holiday calendar entries (Malaysia vs Hong Kong).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('holidays', 'type')) {
            return;
        }

        Schema::table('holidays', function (Blueprint $table) {
            $table->enum('type', ['malaysia', 'hong_kong'])->default('malaysia')->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('holidays', 'type')) {
            return;
        }

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
