<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, clean up orphaned records in irrigation_plot table

        // Count records before cleanup
        $beforeCount = DB::table('irrigation_plot')->count();

        // Remove records where plot_id doesn't exist in plots table
        $orphanedPlots = DB::statement('
            DELETE FROM irrigation_plot
            WHERE plot_id NOT IN (SELECT id FROM plots)
        ');

        // Remove records where irrigation_id doesn't exist in irrigations table
        $orphanedIrrigations = DB::statement('
            DELETE FROM irrigation_plot
            WHERE irrigation_id NOT IN (SELECT id FROM irrigations)
        ');

        // Count records after cleanup
        $afterCount = DB::table('irrigation_plot')->count();
        $removedCount = $beforeCount - $afterCount;

        Log::info("Cleaned up irrigation_plot table. Removed {$removedCount} orphaned records. Remaining: {$afterCount}");

        // Now add the foreign key constraint
        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->foreign('plot_id')->references('id')->on('plots')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->dropForeign(['plot_id']);
        });

        // Note: We don't restore the orphaned records as they were invalid data
    }
};
