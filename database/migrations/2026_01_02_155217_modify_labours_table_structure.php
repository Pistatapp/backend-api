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

        // Check which columns exist before modifying schema
        // Note: user_id is kept for linking user accounts, so we don't drop it
        $columnsToDrop = [];
        $columnsToCheck = ['national_id', 'monthly_salary',
                          'project_start_date', 'project_end_date', 'position', 'salary',
                          'daily_salary', 'type'];

        foreach ($columnsToCheck as $column) {
            if (Schema::hasColumn('labours', $column)) {
                $columnsToDrop[] = $column;
            }
        }

        // First, ensure hourly_wage and overtime_hourly_wage exist (needed for user_id placement)
        if (Schema::hasColumn('labours', 'hourly_wage')) {
            Schema::table('labours', function (Blueprint $table) {
                $table->integer('hourly_wage')->nullable(false)->change();
            });
        } else {
            Schema::table('labours', function (Blueprint $table) {
                $table->integer('hourly_wage')->after('end_work_time');
            });
        }

        if (Schema::hasColumn('labours', 'overtime_hourly_wage')) {
            Schema::table('labours', function (Blueprint $table) {
                $table->integer('overtime_hourly_wage')->nullable(false)->change();
            });
        } else {
            Schema::table('labours', function (Blueprint $table) {
                $table->integer('overtime_hourly_wage')->after('hourly_wage');
            });
        }

        // Now ensure user_id column exists and has proper foreign key constraint
        if (!Schema::hasColumn('labours', 'user_id')) {
            Schema::table('labours', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('overtime_hourly_wage')->constrained()->nullOnDelete();
            });
        } else {
            // If user_id exists, ensure it has proper foreign key constraint
            if ($driverName === 'mysql') {
                try {
                    // Check if foreign key exists
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME
                        FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'labours'
                        AND COLUMN_NAME = 'user_id'
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                    ");

                    if (empty($foreignKeys)) {
                        // Add foreign key if it doesn't exist
                        Schema::table('labours', function (Blueprint $table) {
                            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                        });
                    }
                } catch (\Exception $e) {
                    // Continue if foreign key already exists or other error
                }
            }
        }

        // Add name column first (nullable initially)
        if (!Schema::hasColumn('labours', 'name')) {
            Schema::table('labours', function (Blueprint $table) {
                $table->string('name')->nullable()->after('farm_id');
            });
        }

        // Migrate data: concatenate fname and lname into name
        if (Schema::hasColumn('labours', 'fname') && Schema::hasColumn('labours', 'lname')) {
            if ($driverName === 'mysql') {
                // MySQL: CONCAT_WS automatically handles NULL values and skips them
                DB::statement("UPDATE labours SET name = TRIM(CONCAT_WS(' ', fname, lname)) WHERE name IS NULL");
            } else {
                // SQLite, PostgreSQL: Use || operator with proper NULL handling
                DB::statement("UPDATE labours SET name = TRIM(COALESCE(fname, '') || CASE WHEN fname IS NOT NULL AND fname != '' AND lname IS NOT NULL AND lname != '' THEN ' ' ELSE '' END || COALESCE(lname, '')) WHERE name IS NULL");
            }
        }

        // Make name column not nullable
        Schema::table('labours', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });

        // Drop fname and lname columns now that data is migrated
        if (Schema::hasColumn('labours', 'fname') || Schema::hasColumn('labours', 'lname')) {
            $nameColumnsToDrop = [];
            if (Schema::hasColumn('labours', 'fname')) {
                $nameColumnsToDrop[] = 'fname';
            }
            if (Schema::hasColumn('labours', 'lname')) {
                $nameColumnsToDrop[] = 'lname';
            }
            Schema::table('labours', function (Blueprint $table) use ($nameColumnsToDrop) {
                $table->dropColumn($nameColumnsToDrop);
            });
        }

        // Drop other old columns
        if (!empty($columnsToDrop)) {
            Schema::table('labours', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }

        // Add other new columns
        if (!Schema::hasColumn('labours', 'personnel_number')) {
            Schema::table('labours', function (Blueprint $table) {
                $table->string('personnel_number')->nullable()->after('name');
            });
        }
        if (!Schema::hasColumn('labours', 'image')) {
            Schema::table('labours', function (Blueprint $table) {
                $table->string('image')->nullable();
            });
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('labours', function (Blueprint $table) {
            // Re-add dropped columns
            $table->string('fname')->after('farm_id');
            $table->string('lname')->after('fname');
            $table->string('national_id')->after('lname');
            $table->bigInteger('monthly_salary')->nullable()->after('end_work_time');
            // Note: user_id is kept, so we don't need to re-add it here
            $table->date('project_start_date')->nullable()->after('work_type');
            $table->date('project_end_date')->nullable()->after('project_start_date');
            $table->string('position')->after('mobile');
            $table->bigInteger('salary')->nullable()->after('end_work_time');
            $table->bigInteger('daily_salary')->nullable()->after('salary');
            $table->string('type')->after('farm_id');

            // Drop new columns
            $table->dropColumn(['name', 'personnel_number', 'image']);

            // Revert hourly_wage and overtime_hourly_wage to nullable
            $table->integer('hourly_wage')->nullable()->change();
            $table->integer('overtime_hourly_wage')->nullable()->change();
        });
    }
};
