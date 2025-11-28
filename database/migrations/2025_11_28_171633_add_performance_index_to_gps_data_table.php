<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add optimized composite index for start movement time detection queries
     */
    public function up(): void
    {
        // Skip for SQLite as it has different indexing behavior
        if (DB::getDriverName() !== 'sqlite') {
            // Add composite index optimized for the start movement time detection query
            // This index covers: gps_device_id, date_time, status, speed
            // It enables index-only scans for better performance
            DB::statement('
                CREATE INDEX idx_gps_data_start_time_detection
                ON gps_data (gps_device_id, date_time, status, speed)
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP INDEX idx_gps_data_start_time_detection ON gps_data');
        }
    }
};
