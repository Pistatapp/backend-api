<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Fix the bug where TIMESTAMP columns auto-update to current time on any row update.
     * Change start_time and end_time from TIMESTAMP to DATETIME to prevent automatic updates.
     */
    public function up(): void
    {
        // Check if columns exist and are TIMESTAMP type
        if (Schema::hasColumn('irrigations', 'start_time') && Schema::hasColumn('irrigations', 'end_time')) {
            // Use raw SQL to change TIMESTAMP to DATETIME
            // This prevents MySQL from auto-updating these columns when other fields change
            DB::statement('ALTER TABLE irrigations MODIFY COLUMN start_time DATETIME NOT NULL');
            DB::statement('ALTER TABLE irrigations MODIFY COLUMN end_time DATETIME NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to TIMESTAMP (though this will reintroduce the bug)
        if (Schema::hasColumn('irrigations', 'start_time') && Schema::hasColumn('irrigations', 'end_time')) {
            DB::statement('ALTER TABLE irrigations MODIFY COLUMN start_time TIMESTAMP NOT NULL');
            DB::statement('ALTER TABLE irrigations MODIFY COLUMN end_time TIMESTAMP NULL');
        }
    }
};
