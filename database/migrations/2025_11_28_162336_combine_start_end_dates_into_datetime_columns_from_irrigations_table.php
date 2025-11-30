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
        // Check if the old date column exists (indicating old schema)
        $hasDateColumn = Schema::hasColumn('irrigations', 'date');

        if ($hasDateColumn) {
            // Old schema exists, migrate data first
            $this->migrateExistingData();
        } else {
            // New schema already exists, just ensure datetime columns are properly typed
            $this->ensureDateTimeColumns();
        }
    }

    private function migrateExistingData(): void
    {
        // Add temporary columns for datetime values
        // Use dateTime() instead of timestamp() to prevent MySQL auto-update behavior
        Schema::table('irrigations', function (Blueprint $table) {
            $table->dateTime('start_datetime')->nullable()->after('pump_id');
            $table->dateTime('end_datetime')->nullable()->after('start_datetime');
        });

        // Update data to populate the new datetime columns
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

        // Drop the old columns and rename new ones
        Schema::table('irrigations', function (Blueprint $table) {
            $table->dropColumn(['date', 'start_time', 'end_time']);
            $table->renameColumn('start_datetime', 'start_time');
            $table->renameColumn('end_datetime', 'end_time');
        });
    }

    private function ensureDateTimeColumns(): void
    {
        // Ensure start_time and end_time are datetime columns
        // Use dateTime() instead of timestamp() to prevent MySQL auto-update behavior
        Schema::table('irrigations', function (Blueprint $table) {
            // Check if columns need to be modified to datetime
            $table->dateTime('start_time')->change();
            $table->dateTime('end_time')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Check if datetime columns exist (indicating migration was run)
        $hasDateTimeColumns = !Schema::hasColumn('irrigations', 'date') &&
                             Schema::hasColumn('irrigations', 'start_time') &&
                             Schema::hasColumn('irrigations', 'end_time');

        if ($hasDateTimeColumns) {
            // Reverse the datetime migration
            $this->reverseDateTimeMigration();
        }
        // If date column exists, assume already reversed or never migrated
    }

    private function reverseDateTimeMigration(): void
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
            ->get();

        foreach ($irrigations as $irrigation) {
            $startDateTime = \Carbon\Carbon::parse($irrigation->start_time);

            DB::table('irrigations')
                ->where('id', $irrigation->id)
                ->update([
                    'date' => $startDateTime->toDateString(),
                    'start_time_old' => $startDateTime->toTimeString(),
                    'end_time_old' => $irrigation->end_time ? \Carbon\Carbon::parse($irrigation->end_time)->toTimeString() : null,
                ]);
        }

        // Drop the datetime columns and rename old ones back
        Schema::table('irrigations', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
            $table->renameColumn('start_time_old', 'start_time');
            $table->renameColumn('end_time_old', 'end_time');
        });
    }
};
