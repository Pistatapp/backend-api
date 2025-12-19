<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop indexes that reference columns being removed
        // These indexes will become invalid when columns are dropped
        // Use DB::statement for safety in case indexes don't exist
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_date_starting_point ON gps_reports');
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_date_ending_point ON gps_reports');
            DB::statement('DROP INDEX IF EXISTS idx_working_points ON gps_reports');

            // Drop redundant indexes (keep one of each pair)
            // Drop the older version, keep the v2 version for date_time index
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_device_datetime ON gps_reports');

            // Drop one of the redundant speed indexes (keep idx_device_datetime_speed, drop the older one)
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_device_datetime_speed ON gps_reports');
        } else {
            // For SQLite, use Schema builder
            Schema::table('gps_reports', function (Blueprint $table) {
                try {
                    $table->dropIndex('idx_gps_reports_date_starting_point');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('idx_gps_reports_date_ending_point');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('idx_working_points');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('idx_gps_reports_device_datetime');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('idx_gps_reports_device_datetime_speed');
                } catch (\Exception $e) {}
            });
        }

        // Now drop the columns
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dropColumn([
                'raw_data',
                'is_stopped',
                'stoppage_time',
                'is_starting_point',
                'is_ending_point',
                'on_time',
                'server_received_at',
                'gps_utc',
                'ingestion_delay_sec',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add the columns
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->text('raw_data')->nullable();
            $table->boolean('is_stopped')->default(false);
            $table->unsignedBigInteger('stoppage_time')->default(0);
            $table->boolean('is_starting_point')->default(false);
            $table->boolean('is_ending_point')->default(false);
            $table->timestamp('on_time')->nullable();
            $table->timestamp('server_received_at')->nullable();
            $table->timestamp('gps_utc')->nullable();
            $table->unsignedInteger('ingestion_delay_sec')->nullable();
        });

        // Re-add the indexes
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->index(['date_time', 'is_starting_point'], 'idx_gps_reports_date_starting_point');
            $table->index(['date_time', 'is_ending_point'], 'idx_gps_reports_date_ending_point');
            $table->index(['date_time', 'is_starting_point', 'is_ending_point'], 'idx_working_points');
            $table->index(['date_time'], 'idx_gps_reports_device_datetime');
            $table->index(['date_time', 'speed'], 'idx_gps_reports_device_datetime_speed');
        });
    }
};
