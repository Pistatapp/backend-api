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
        // Skip for SQLite as it has different behavior
        if (DB::getDriverName() !== 'sqlite') {
            // Drop existing indexes that reference gps_device_id
            DB::statement('DROP INDEX IF EXISTS idx_gps_data_start_time_detection ON gps_data');
            DB::statement('DROP INDEX IF EXISTS gps_data_device_id_index ON gps_data');
            DB::statement('DROP INDEX IF EXISTS gps_data_device_id_date_time_index ON gps_data');
        }

        // First, add a temporary tractor_id column
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('tractor_id')->nullable()->after('gps_device_id');
        });

        // Migrate data: map gps_device_id to tractor_id via gps_devices table
        DB::statement('
            UPDATE gps_reports
            INNER JOIN gps_devices ON gps_reports.gps_device_id = gps_devices.id
            SET gps_reports.tractor_id = gps_devices.tractor_id
            WHERE gps_devices.tractor_id IS NOT NULL
        ');

        // Remove rows where tractor_id is null (orphaned GPS data)
        DB::statement('DELETE FROM gps_reports WHERE tractor_id IS NULL');

        // Make tractor_id NOT NULL
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('tractor_id')->nullable(false)->change();
        });

        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dropForeign(['gps_device_id']);
        });


        // Drop the old gps_device_id column
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dropColumn('gps_device_id');
        });

        // Note: Foreign key constraint is not added because partitioned tables don't support foreign keys in MySQL
        // Data integrity is maintained at the application level

        // Recreate indexes with tractor_id
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('gps_reports', function (Blueprint $table) {
                $table->index('tractor_id');
                $table->index(['tractor_id', 'date_time']);
            });

            // Drop the old performance index if it exists (created with gps_device_id)
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_start_time_detection ON gps_reports');

            // Recreate the performance index with tractor_id
            DB::statement('
                CREATE INDEX idx_gps_reports_start_time_detection
                ON gps_reports (tractor_id, date_time, status, speed)
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Drop indexes that reference tractor_id
            DB::statement('DROP INDEX IF EXISTS idx_gps_reports_start_time_detection ON gps_reports');
            Schema::table('gps_reports', function (Blueprint $table) {
                $table->dropIndex(['tractor_id']);
                $table->dropIndex(['tractor_id', 'date_time']);
            });
        }

        // Note: No foreign key to drop since partitioned tables don't support foreign keys

        // Add back gps_device_id column
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('gps_device_id')->nullable()->after('tractor_id');
        });

        // Migrate data back: map tractor_id to gps_device_id via gps_devices table
        DB::statement('
            UPDATE gps_reports
            INNER JOIN gps_devices ON gps_reports.tractor_id = gps_devices.tractor_id
            SET gps_reports.gps_device_id = gps_devices.id
            WHERE gps_devices.id IS NOT NULL
        ');

        // Make gps_device_id NOT NULL
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('gps_device_id')->nullable(false)->change();
        });

        // Drop tractor_id column
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dropColumn('tractor_id');
        });

        // Recreate indexes with gps_device_id
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('gps_reports', function (Blueprint $table) {
                $table->index('gps_device_id');
                $table->index(['gps_device_id', 'date_time']);
            });

            DB::statement('
                CREATE INDEX idx_gps_reports_start_time_detection
                ON gps_reports (gps_device_id, date_time, status, speed)
            ');
        }
    }
};
