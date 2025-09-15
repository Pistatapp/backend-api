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
        Schema::table('gps_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('gps_reports', 'directions')) {
                $table->json('directions')->nullable()->after('speed');
            }
            if (Schema::hasColumn('gps_reports', 'ns_direction')) {
                $table->dropColumn('ns_direction');
            }
            if (Schema::hasColumn('gps_reports', 'ew_direction')) {
                $table->dropColumn('ew_direction');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_reports', function (Blueprint $table) {
            if (Schema::hasColumn('gps_reports', 'directions')) {
                $table->dropColumn('directions');
            }
            if (Schema::hasColumn('gps_reports', 'ns_direction')) {
                $table->unsignedInteger('ns_direction')->nullable()->after('directions');
            }
            if (Schema::hasColumn('gps_reports', 'ew_direction')) {
                $table->unsignedInteger('ew_direction')->nullable()->after('directions');
            }
        });
    }
};
