<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->shouldRunOnGpsConnection()) {
            return;
        }

        Schema::connection('mysql_gps')->table('gps_data', function (Blueprint $table) {
            $table->unique(['imei', 'date_time'], 'gps_data_imei_date_time_unique');
        });
    }

    public function down(): void
    {
        if (! $this->shouldRunOnGpsConnection()) {
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
};
