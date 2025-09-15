<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gps_reports', function (Blueprint $table) {
            // Composite index for device and date_time - most common query pattern
            $table->index(['gps_device_id', 'date_time'], 'idx_gps_reports_device_datetime_v2');

            // Index for working points detection
            $table->index(['date_time', 'is_starting_point', 'is_ending_point'], 'idx_working_points');

            // Index for speed-based queries
            $table->index(['gps_device_id', 'date_time', 'speed'], 'idx_device_datetime_speed');

            // Index for status-based queries
            $table->index(['gps_device_id', 'date_time', 'status'], 'idx_device_datetime_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dropIndex('idx_gps_reports_device_datetime_v2');
            $table->dropIndex('idx_working_points');
            $table->dropIndex('idx_device_datetime_speed');
            $table->dropIndex('idx_device_datetime_status');
        });
    }
};
