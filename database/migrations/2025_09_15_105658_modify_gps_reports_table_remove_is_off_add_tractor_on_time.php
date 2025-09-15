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
            // Remove the is_off column
            $table->dropColumn('is_off');

            // Add tractor_on_time column to track when tractor became on (status changed from 0 to 1)
            // after official work start time
            $table->timestamp('on_time')->nullable()->after('is_ending_point');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_reports', function (Blueprint $table) {
            // Add back the is_off column
            $table->boolean('is_off')->default(false)->after('is_ending_point');

            // Remove the tractor_on_time column
            $table->dropColumn('on_time');
        });
    }
};
