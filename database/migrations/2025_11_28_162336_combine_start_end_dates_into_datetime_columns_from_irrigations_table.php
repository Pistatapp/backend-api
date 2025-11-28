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
        // Add temporary columns for datetime values
        Schema::table('irrigations', function (Blueprint $table) {
            $table->timestamp('start_datetime')->nullable()->after('pump_id');
            $table->timestamp('end_datetime')->nullable()->after('start_datetime');
        });

        // Update data to populate the new datetime columns
        // Use raw SQL that works with both MySQL and SQLite
        $irrigations = DB::table('irrigations')
            ->whereNotNull('date')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->get();

        foreach ($irrigations as $irrigation) {
            $startDateTime = $irrigation->date . ' ' . $irrigation->start_time;
            $endDateTime = $irrigation->date . ' ' . $irrigation->end_time;

            DB::table('irrigations')
                ->where('id', $irrigation->id)
                ->update([
                    'start_datetime' => $startDateTime,
                    'end_datetime' => $endDateTime,
                ]);
        }

        // Drop the old columns
        Schema::table('irrigations', function (Blueprint $table) {
            $table->dropColumn(['date', 'start_time', 'end_time']);
        });

        // Rename the new columns to replace the old ones
        Schema::table('irrigations', function (Blueprint $table) {
            $table->renameColumn('start_datetime', 'start_time');
            $table->renameColumn('end_datetime', 'end_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add back the old columns
        Schema::table('irrigations', function (Blueprint $table) {
            $table->date('date')->nullable()->after('pump_id');
            $table->time('start_time_old')->nullable()->after('date');
            $table->time('end_time_old')->nullable()->after('start_time_old');
        });

        // Extract date and time from datetime columns
        $irrigations = DB::table('irrigations')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->get();

        foreach ($irrigations as $irrigation) {
            $startDateTime = \Carbon\Carbon::parse($irrigation->start_time);
            $endDateTime = \Carbon\Carbon::parse($irrigation->end_time);

            DB::table('irrigations')
                ->where('id', $irrigation->id)
                ->update([
                    'date' => $startDateTime->toDateString(),
                    'start_time_old' => $startDateTime->toTimeString(),
                    'end_time_old' => $endDateTime->toTimeString(),
                ]);
        }

        // Drop the datetime columns
        Schema::table('irrigations', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });

        // Rename the old columns back
        Schema::table('irrigations', function (Blueprint $table) {
            $table->renameColumn('start_time_old', 'start_time');
            $table->renameColumn('end_time_old', 'end_time');
        });
    }
};
