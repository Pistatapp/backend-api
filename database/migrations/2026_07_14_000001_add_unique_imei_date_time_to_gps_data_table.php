<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional unique (imei, date_time) on mysql_gps.gps_data.
 *
 * IMPORTANT: Do NOT run a full-table self-join DELETE on production gps_data.
 * That query locks the table for days on 10M+ rows and blocks ALL inserts
 * (live WS still works from memory → empty path). Seen in prod 2026-08.
 *
 * Default: no-op when the index is missing (IngestGpsData uses plain insert).
 * Only attempts CREATE UNIQUE when GPS_FORCE_UNIQUE_IMEI_DATETIME=true and
 * after an explicit offline dedupe by DBA.
 */
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

        // Production-safe default: leave table without unique index.
        if (! filter_var(env('GPS_FORCE_UNIQUE_IMEI_DATETIME', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        // Forced path: create unique only — never auto-DELETE duplicates here.
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

        return ((int) ($result->count ?? 0)) > 0;
    }
};
