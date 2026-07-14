<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->shouldRunOnGpsConnection()) {
            return;
        }

        if ($this->uniqueIndexExists()) {
            return;
        }

        // Remove existing duplicates before adding the unique constraint.
        // Keeps the row with the lowest id for each (imei, date_time) pair.
        DB::connection('mysql_gps')->statement('
            DELETE t1 FROM gps_data t1
            INNER JOIN gps_data t2
                ON t1.imei = t2.imei
                AND t1.date_time = t2.date_time
                AND t1.id > t2.id
        ');

        Schema::connection('mysql_gps')->table('gps_data', function (Blueprint $table) {
            $table->unique(['imei', 'date_time'], 'gps_data_imei_date_time_unique');
        });
    }

    public function down(): void
    {
        if (! $this->shouldRunOnGpsConnection()) {
            return;
        }

        if (! $this->uniqueIndexExists()) {
            return;
        }

        Schema::connection('mysql_gps')->table('gps_data', function (Blueprint $table) {
            $table->dropUnique('gps_data_imei_date_time_unique');
        });
    }

    private function shouldRunOnGpsConnection(): bool
    {
        $connection = config('database.connections.mysql_gps');

        if (($connection['driver'] ?? null) !== 'mysql') {
            return false;
        }

        if (($connection['database'] ?? null) === ':memory:') {
            return false;
        }

        try {
            Schema::connection('mysql_gps')->getConnection()->getPdo();
        } catch (\Throwable) {
            return false;
        }

        return Schema::connection('mysql_gps')->hasTable('gps_data');
    }

    private function uniqueIndexExists(): bool
    {
        $database = config('database.connections.mysql_gps.database');

        $result = DB::connection('mysql_gps')->selectOne('
            SELECT COUNT(*) AS count
            FROM information_schema.statistics
            WHERE table_schema = ?
              AND table_name = ?
              AND index_name = ?
        ', [$database, 'gps_data', 'gps_data_imei_date_time_unique']);

        return ($result->count ?? 0) > 0;
    }
};
