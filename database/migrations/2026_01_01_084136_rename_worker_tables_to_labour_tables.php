<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rename worker tables to labour tables
        $tables = [
            'worker_gps_data' => 'labour_gps_data',
            'worker_attendance_sessions' => 'labour_attendance_sessions',
            'worker_daily_reports' => 'labour_daily_reports',
            'worker_monthly_payrolls' => 'labour_monthly_payrolls',
            'worker_shift_schedules' => 'labour_shift_schedules',
        ];

        foreach ($tables as $oldName => $newName) {
            if (Schema::hasTable($oldName) && !Schema::hasTable($newName)) {
                Schema::rename($oldName, $newName);
            }
        }

        // Update unique constraint names if they exist
        if (Schema::hasTable('labour_shift_schedules')) {
            try {
                DB::statement("ALTER TABLE labour_shift_schedules DROP INDEX unique_worker_shift_date");
                DB::statement("ALTER TABLE labour_shift_schedules ADD UNIQUE KEY unique_labour_shift_date (labour_id, shift_id, scheduled_date)");
            } catch (\Exception $e) {
                // Index might not exist or already renamed
            }
        }

        if (Schema::hasTable('labour_attendance_sessions')) {
            try {
                DB::statement("ALTER TABLE labour_attendance_sessions DROP INDEX unique_worker_date_session");
                DB::statement("ALTER TABLE labour_attendance_sessions ADD UNIQUE KEY unique_labour_date_session (labour_id, date)");
            } catch (\Exception $e) {
                // Index might not exist or already renamed
            }
        }

        if (Schema::hasTable('labour_daily_reports')) {
            try {
                DB::statement("ALTER TABLE labour_daily_reports DROP INDEX unique_worker_date_report");
                DB::statement("ALTER TABLE labour_daily_reports ADD UNIQUE KEY unique_labour_date_report (labour_id, date)");
            } catch (\Exception $e) {
                // Index might not exist or already renamed
            }
        }

        if (Schema::hasTable('labour_monthly_payrolls')) {
            try {
                DB::statement("ALTER TABLE labour_monthly_payrolls DROP INDEX unique_worker_month_year");
                DB::statement("ALTER TABLE labour_monthly_payrolls ADD UNIQUE KEY unique_labour_month_year (labour_id, month, year)");
            } catch (\Exception $e) {
                // Index might not exist or already renamed
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename labour tables back to worker tables
        $tables = [
            'labour_gps_data' => 'worker_gps_data',
            'labour_attendance_sessions' => 'worker_attendance_sessions',
            'labour_daily_reports' => 'worker_daily_reports',
            'labour_monthly_payrolls' => 'worker_monthly_payrolls',
            'labour_shift_schedules' => 'worker_shift_schedules',
        ];

        foreach ($tables as $oldName => $newName) {
            if (Schema::hasTable($oldName) && !Schema::hasTable($newName)) {
                Schema::rename($oldName, $newName);
            }
        }

        // Revert unique constraint names
        if (Schema::hasTable('worker_shift_schedules')) {
            try {
                DB::statement("ALTER TABLE worker_shift_schedules DROP INDEX unique_labour_shift_date");
                DB::statement("ALTER TABLE worker_shift_schedules ADD UNIQUE KEY unique_worker_shift_date (labour_id, shift_id, scheduled_date)");
            } catch (\Exception $e) {
                // Index might not exist
            }
        }

        if (Schema::hasTable('worker_attendance_sessions')) {
            try {
                DB::statement("ALTER TABLE worker_attendance_sessions DROP INDEX unique_labour_date_session");
                DB::statement("ALTER TABLE worker_attendance_sessions ADD UNIQUE KEY unique_worker_date_session (labour_id, date)");
            } catch (\Exception $e) {
                // Index might not exist
            }
        }

        if (Schema::hasTable('worker_daily_reports')) {
            try {
                DB::statement("ALTER TABLE worker_daily_reports DROP INDEX unique_labour_date_report");
                DB::statement("ALTER TABLE worker_daily_reports ADD UNIQUE KEY unique_worker_date_report (labour_id, date)");
            } catch (\Exception $e) {
                // Index might not exist
            }
        }

        if (Schema::hasTable('worker_monthly_payrolls')) {
            try {
                DB::statement("ALTER TABLE worker_monthly_payrolls DROP INDEX unique_labour_month_year");
                DB::statement("ALTER TABLE worker_monthly_payrolls ADD UNIQUE KEY unique_worker_month_year (labour_id, month, year)");
            } catch (\Exception $e) {
                // Index might not exist
            }
        }
    }
};
