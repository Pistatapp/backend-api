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
        Schema::table('labours', function (Blueprint $table) {
            // Remove columns
            $table->dropColumn(['position', 'project_start_date', 'project_end_date', 'type', 'salary', 'daily_salary']);
            
            // Add new columns
            $table->foreignId('user_id')->nullable()->after('farm_id')->constrained()->nullOnDelete();
            $table->bigInteger('hourly_wage')->nullable()->after('monthly_salary');
            $table->bigInteger('overtime_hourly_wage')->nullable()->after('hourly_wage');
            
            // Modify work_days column from tinyInteger to json (only if column exists)
            if (Schema::hasColumn('labours', 'work_days')) {
                $driverName = \Illuminate\Support\Facades\DB::getDriverName();
                if ($driverName === 'sqlite') {
                    // SQLite doesn't support MODIFY COLUMN easily, so we skip for SQLite
                    // The column type will be handled by the model cast
                } else {
                    $table->json('work_days')->nullable()->change();
                }
            } else {
                $table->json('work_days')->nullable()->after('work_type');
            }
        });

        // Rename the table
        Schema::rename('labours', 'employees');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('employees', 'labours');
        
        Schema::table('labours', function (Blueprint $table) {
            // Add back removed columns
            $table->string('position')->nullable();
            $table->date('project_start_date')->nullable();
            $table->date('project_end_date')->nullable();
            $table->string('type')->nullable();
            $table->bigInteger('salary')->nullable();
            $table->bigInteger('daily_salary')->nullable();
            
            // Remove new columns
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'hourly_wage', 'overtime_hourly_wage', 'work_days']);
        });
    }
};
