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
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->renameColumn('total_in_zone_duration', 'in_zone_duration');
            $table->renameColumn('total_out_zone_duration', 'outside_zone_duration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->renameColumn('in_zone_duration', 'total_in_zone_duration');
            $table->renameColumn('outside_zone_duration', 'total_out_zone_duration');
        });
    }
};
