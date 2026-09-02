<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only. Existing rows remain untouched and nullable columns keep
     * old writers and readers compatible.
     */
    public function up(): void
    {
        if (app()->environment('testing') || config('database.connections.mysql_gps.database') === ':memory:') {
            return;
        }

        $schema = Schema::connection('mysql_gps');
        if (! $schema->hasTable('gps_data')) {
            return;
        }

        $columns = [];
        if (! $schema->hasColumn('gps_data', 'event_id')) {
            $columns[] = 'event_id';
        }
        if (! $schema->hasColumn('gps_data', 'batch_index')) {
            $columns[] = 'batch_index';
        }

        if ($columns === []) {
            return;
        }

        $schema->table('gps_data', function (Blueprint $table) use ($columns) {
            if (in_array('event_id', $columns, true)) {
                $table->string('event_id', 64)->nullable()->index('gps_data_event_id_index');
            }
            if (in_array('batch_index', $columns, true)) {
                $table->unsignedInteger('batch_index')->nullable()->index('gps_data_batch_index_index');
            }
        });
    }

    public function down(): void
    {
        if (app()->environment('testing') || config('database.connections.mysql_gps.database') === ':memory:') {
            return;
        }

        $schema = Schema::connection('mysql_gps');
        if (! $schema->hasTable('gps_data')) {
            return;
        }

        $schema->table('gps_data', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('gps_data', 'event_id')) {
                $table->dropIndex('gps_data_event_id_index');
                $table->dropColumn('event_id');
            }
            if ($schema->hasColumn('gps_data', 'batch_index')) {
                $table->dropIndex('gps_data_batch_index_index');
                $table->dropColumn('batch_index');
            }
        });
    }
};
