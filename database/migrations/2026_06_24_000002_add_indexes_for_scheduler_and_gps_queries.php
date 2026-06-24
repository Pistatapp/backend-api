<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tractor_tasks')) {
            Schema::table('tractor_tasks', function (Blueprint $table) {
                $table->index(['date', 'status', 'end_time'], 'tractor_tasks_date_status_end_time_index');
            });
        }

        if (Schema::hasTable('gps_data') && Schema::hasColumn('gps_data', 'tractor_id')) {
            Schema::table('gps_data', function (Blueprint $table) {
                $table->index(['tractor_id', 'date_time'], 'gps_data_tractor_id_date_time_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tractor_tasks')) {
            Schema::table('tractor_tasks', function (Blueprint $table) {
                $table->dropIndex('tractor_tasks_date_status_end_time_index');
            });
        }

        if (Schema::hasTable('gps_data')) {
            Schema::table('gps_data', function (Blueprint $table) {
                $table->dropIndex('gps_data_tractor_id_date_time_index');
            });
        }
    }
};
