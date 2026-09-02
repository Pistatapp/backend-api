<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger is additive and intentionally separate from the 18M+ row
     * gps_data table, so installing it does not rewrite historical GPS data.
     */
    public function up(): void
    {
        // The GPS connection intentionally points at production in .env. Keep
        // RefreshDatabase on the isolated SQLite test database side-effect free.
        if (app()->environment('testing') || config('database.connections.mysql_gps.database') === ':memory:') {
            return;
        }

        $schema = Schema::connection('mysql_gps');

        if ($schema->hasTable('gps_ingest_events')) {
            return;
        }

        $schema->create('gps_ingest_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_id', 64)->unique('gps_ingest_events_event_id_unique');
            $table->string('trace_id', 128)->nullable()->index('gps_ingest_events_trace_id_index');
            $table->string('imei', 20)->nullable()->index('gps_ingest_events_imei_index');
            $table->dateTime('device_recorded_at')->nullable();
            $table->dateTime('gateway_received_at')->nullable();
            $table->char('payload_hash', 64)->nullable()->index('gps_ingest_events_payload_hash_index');
            $table->longText('raw_payload');
            $table->string('raw_reference', 255)->nullable();
            $table->unsignedInteger('batch_index')->nullable();
            $table->string('status', 32)->index('gps_ingest_events_status_index');
            $table->text('error_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('persisted_at')->nullable();
            $table->timestamps();

            $table->index(['imei', 'device_recorded_at'], 'gps_ingest_events_imei_device_time_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql_gps')->dropIfExists('gps_ingest_events');
    }
};
