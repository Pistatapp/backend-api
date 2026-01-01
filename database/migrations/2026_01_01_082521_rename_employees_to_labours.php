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
        $driverName = DB::getDriverName();

        // Rename employee_team table to labour_team
        if (Schema::hasTable('employee_team')) {
            Schema::rename('employee_team', 'labour_team');
        }

        // Rename employees table to labours
        if (Schema::hasTable('employees')) {
            Schema::rename('employees', 'labours');
        }

        // Update foreign key columns in related tables
        $tablesToUpdate = [
            'worker_gps_data' => 'employee_id',
            'worker_attendance_sessions' => 'employee_id',
            'worker_daily_reports' => 'employee_id',
            'worker_monthly_payrolls' => 'employee_id',
            'worker_shift_schedules' => 'employee_id',
            'labour_team' => 'employee_id',
            'teams' => 'supervisor_id',
        ];

        foreach ($tablesToUpdate as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                if ($driverName === 'mysql') {
                    // Drop foreign key constraint
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME 
                        FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = '{$table}' 
                        AND COLUMN_NAME = '{$column}'
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                    ");

                    foreach ($foreignKeys as $fk) {
                        Schema::table($table, function (Blueprint $table) use ($fk) {
                            $table->dropForeign([$fk->CONSTRAINT_NAME]);
                        });
                    }

                    // Rename column
                    DB::statement("ALTER TABLE {$table} CHANGE {$column} labour_id BIGINT UNSIGNED NOT NULL");

                    // Re-add foreign key
                    Schema::table($table, function (Blueprint $table) use ($column) {
                        $table->foreign('labour_id')->references('id')->on('labours')->cascadeOnDelete();
                    });
                } elseif ($driverName === 'sqlite') {
                    // SQLite doesn't support column renaming easily, so we'll skip for SQLite
                    // The model relationships will handle the mapping
                }
            }
        }

        // Update unique constraint name in worker_shift_schedules if it exists
        if (Schema::hasTable('worker_shift_schedules')) {
            try {
                DB::statement("ALTER TABLE worker_shift_schedules DROP INDEX unique_worker_shift_date");
                DB::statement("ALTER TABLE worker_shift_schedules ADD UNIQUE KEY unique_worker_shift_date (labour_id, shift_id, scheduled_date)");
            } catch (\Exception $e) {
                // Ignore if constraint doesn't exist
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driverName = DB::getDriverName();

        // Update foreign key columns back
        $tablesToUpdate = [
            'worker_gps_data' => 'labour_id',
            'worker_attendance_sessions' => 'labour_id',
            'worker_daily_reports' => 'labour_id',
            'worker_monthly_payrolls' => 'labour_id',
            'worker_shift_schedules' => 'labour_id',
            'labour_team' => 'labour_id',
            'teams' => 'supervisor_id',
        ];

        foreach ($tablesToUpdate as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                if ($driverName === 'mysql') {
                    // Drop foreign key constraint
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME 
                        FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = '{$table}' 
                        AND COLUMN_NAME = '{$column}'
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                    ");

                    foreach ($foreignKeys as $fk) {
                        Schema::table($table, function (Blueprint $table) use ($fk) {
                            $table->dropForeign([$fk->CONSTRAINT_NAME]);
                        });
                    }

                    // Rename column back
                    $newColumn = str_replace('labour_id', 'employee_id', $column);
                    if ($column === 'supervisor_id') {
                        $newColumn = 'supervisor_id'; // Keep as is for teams table
                    }
                    DB::statement("ALTER TABLE {$table} CHANGE {$column} {$newColumn} BIGINT UNSIGNED NOT NULL");

                    // Re-add foreign key
                    Schema::table($table, function (Blueprint $table) use ($newColumn) {
                        $table->foreign($newColumn)->references('id')->on('employees')->cascadeOnDelete();
                    });
                }
            }
        }

        // Rename labour_team table back to employee_team
        if (Schema::hasTable('labour_team')) {
            Schema::rename('labour_team', 'employee_team');
        }

        // Rename labours table back to employees
        if (Schema::hasTable('labours')) {
            Schema::rename('labours', 'employees');
        }
    }
};
