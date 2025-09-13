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
        Schema::table('gps_reports', function (Blueprint $table) {
            // Add the new directions column as JSON
            $table->json('directions')->after('status')->nullable();
        });

        // Migrate existing data from ew_direction and ns_direction to directions
        DB::table('gps_reports')->chunkById(1000, function ($reports) {
            foreach ($reports as $report) {
                DB::table('gps_reports')
                    ->where('id', $report->id)
                    ->update([
                        'directions' => json_encode([
                            'ew' => $report->ew_direction ?? 0,
                            'ns' => $report->ns_direction ?? 0
                        ])
                    ]);
            }
        });

        Schema::table('gps_reports', function (Blueprint $table) {
            // Drop the old columns
            $table->dropColumn(['ew_direction', 'ns_direction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_reports', function (Blueprint $table) {
            // Add back the old columns
            $table->unsignedInteger('ew_direction')->after('status')->nullable();
            $table->unsignedInteger('ns_direction')->after('ew_direction')->nullable();
        });

        // Migrate data back from directions to separate columns
        DB::table('gps_reports')->chunkById(1000, function ($reports) {
            foreach ($reports as $report) {
                $directions = json_decode($report->directions, true);
                DB::table('gps_reports')
                    ->where('id', $report->id)
                    ->update([
                        'ew_direction' => $directions['ew'] ?? 0,
                        'ns_direction' => $directions['ns'] ?? 0
                    ]);
            }
        });

        Schema::table('gps_reports', function (Blueprint $table) {
            // Drop the directions column
            $table->dropColumn('directions');
        });
    }
};
