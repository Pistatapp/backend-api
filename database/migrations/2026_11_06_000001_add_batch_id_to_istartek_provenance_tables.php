<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('mysql_gps');

        foreach (['gps_event_time_provenance', 'gps_event_time_provenance_events'] as $tableName) {
            if (! $schema->hasTable($tableName) || $schema->hasColumn($tableName, 'batch_id')) {
                continue;
            }

            $schema->table($tableName, function (Blueprint $table): void {
                $table->string('batch_id', 128)->nullable()->after('verification_status');
                $table->index('batch_id');
            });
        }
    }

    public function down(): void
    {
        // Additive provenance metadata is retained for rollback safety.
    }
};
