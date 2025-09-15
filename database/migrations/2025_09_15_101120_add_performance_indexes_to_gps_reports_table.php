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
            $table->index(['gps_device_id', 'date_time'], 'idx_gps_reports_device_datetime');

            // Index for starting point detection queries
            $table->index(['date_time', 'is_starting_point'], 'idx_gps_reports_date_starting_point');

            // Index for ending point detection queries
            $table->index(['date_time', 'is_ending_point'], 'idx_gps_reports_date_ending_point');

            // Composite index for speed-based queries within time windows
            $table->index(['gps_device_id', 'date_time', 'speed'], 'idx_gps_reports_device_datetime_speed');

            // Index for IMEI-based queries (if still used)
            $table->index('imei', 'idx_gps_reports_imei');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dropIndex('idx_gps_reports_device_datetime');
            $table->dropIndex('idx_gps_reports_date_starting_point');
            $table->dropIndex('idx_gps_reports_date_ending_point');
            $table->dropIndex('idx_gps_reports_device_datetime_speed');
            $table->dropIndex('idx_gps_reports_imei');
        });
    }
};
