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
        Schema::table('tractor_reports', function (Blueprint $table) {
            $table->renameColumn('trucktor_id', 'tractor_id');
        });

        Schema::table('tractor_tasks', function (Blueprint $table) {
            $table->renameColumn('trucktor_id', 'tractor_id');
        });

        Schema::table('gps_daily_reports', function (Blueprint $table) {
            $table->renameColumn('trucktor_id', 'tractor_id');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->renameColumn('trucktor_id', 'tractor_id');
        });

        Schema::table('gps_devices', function (Blueprint $table) {
            $table->renameColumn('trucktor_id', 'tractor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trucktor_reports', function (Blueprint $table) {
            $table->renameColumn('tractor_id', 'trucktor_id');
        });

        Schema::table('trucktor_tasks', function (Blueprint $table) {
            $table->renameColumn('tractor_id', 'trucktor_id');
        });

        Schema::table('gps_daily_reports', function (Blueprint $table) {
            $table->renameColumn('tractor_id', 'trucktor_id');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->renameColumn('tractor_id', 'trucktor_id');
        });

        Schema::table('gps_devices', function (Blueprint $table) {
            $table->renameColumn('tractor_id', 'trucktor_id');
        });
    }
};
