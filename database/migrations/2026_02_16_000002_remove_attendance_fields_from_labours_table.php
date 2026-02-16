<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Remove attendance-related fields from labours table.
     */
    public function up(): void
    {
        if (! Schema::hasTable('labours')) {
            return;
        }

        $columnsToDrop = [];
        $attendanceColumns = [
            'work_type',
            'work_days',
            'work_hours',
            'start_work_time',
            'end_work_time',
            'hourly_wage',
            'overtime_hourly_wage',
            'attendence_tracking_enabled',
            'imei',
            'is_working',
        ];

        foreach ($attendanceColumns as $column) {
            if (Schema::hasColumn('labours', $column)) {
                $columnsToDrop[] = $column;
            }
        }

        if (! empty($columnsToDrop)) {
            Schema::table('labours', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('labours')) {
            return;
        }

        Schema::table('labours', function (Blueprint $table) {
            $table->string('work_type')->nullable()->after('mobile');
            $table->json('work_days')->nullable()->after('work_type');
            $table->decimal('work_hours', 5, 2)->nullable()->after('work_days');
            $table->time('start_work_time')->nullable()->after('work_hours');
            $table->time('end_work_time')->nullable()->after('start_work_time');
            $table->integer('hourly_wage')->default(0)->after('end_work_time');
            $table->integer('overtime_hourly_wage')->default(0)->after('hourly_wage');
            $table->boolean('attendence_tracking_enabled')->default(false)->after('overtime_hourly_wage');
            $table->string('imei')->nullable()->after('attendence_tracking_enabled');
            $table->boolean('is_working')->default(false)->after('imei');
        });
    }
};
