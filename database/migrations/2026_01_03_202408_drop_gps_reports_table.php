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
        // Drop all indexes on gps_reports table
        if (DB::getDriverName() !== 'sqlite') {
            // Drop named indexes
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_device_datetime ON gps_reports');
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_date_starting_point ON gps_reports');
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_date_ending_point ON gps_reports');
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_device_datetime_speed ON gps_reports');
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_imei ON gps_reports');
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_start_time_detection ON gps_reports');
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_device_datetime_v2 ON gps_reports');

            // Drop indexes that might exist on columns
            Schema::table('gps_reports', function (Blueprint $table) {
                $indexes = ['gps_device_id', 'tractor_id', 'imei', 'date_time'];
                foreach ($indexes as $index) {
                    try {
                        $table->dropIndex([$index]);
                    } catch (\Exception $e) {
                        // Index might not exist, continue
                    }
                }
            });

            // Drop composite indexes
            try {
                Schema::table('gps_reports', function (Blueprint $table) {
                    $table->dropIndex(['gps_device_id', 'date_time']);
                    $table->dropIndex(['tractor_id', 'date_time']);
                });
            } catch (\Exception $e) {
                // Indexes might not exist, continue
            }
        }

        // Drop foreign key constraints if they exist
        try {
            Schema::table('gps_reports', function (Blueprint $table) {
                $table->dropForeign(['gps_device_id']);
            });
        } catch (\Exception $e) {
            // Foreign key might not exist, continue
        }

        // Drop the table
        Schema::dropIfExists('gps_reports');
    }

    /**
     * Reverse the migrations.
     *
     * Note: This migration permanently removes the gps_reports table.
     * Reversing would require recreating the entire table structure,
     * which is not recommended as this table is being removed.
     */
    public function down(): void
    {
        // This migration is not reversible as it permanently removes the gps_reports table.
        // If you need to restore this table, you would need to restore from a database backup
        // or recreate it using the original migration files.
    }
};
