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
            $table->renameColumn('direction', 'ew_direction');
            $table->unsignedInteger('ns_direction')->after('ew_direction')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->renameColumn('ew_direction', 'direction');
            $table->dropColumn('ns_direction');
        });
    }
};
