<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_trackings', function (Blueprint $table) {
            $table->dropColumn('imei');
        });

        Schema::table('attendance_trackings', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('overtime_hourly_wage');
        });

        DB::table('attendance_trackings')
            ->update(['enabled' => DB::raw('attendance_tracking_enabled')]);

        Schema::table('attendance_trackings', function (Blueprint $table) {
            $table->dropColumn('attendance_tracking_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_trackings', function (Blueprint $table) {
            $table->boolean('attendance_tracking_enabled')->default(true)->after('overtime_hourly_wage');
        });

        DB::table('attendance_trackings')
            ->update(['attendance_tracking_enabled' => DB::raw('enabled')]);

        Schema::table('attendance_trackings', function (Blueprint $table) {
            $table->dropColumn('enabled');
            $table->string('imei')->nullable()->after('overtime_hourly_wage');
        });
    }
};
