<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This is an additive, independent outbox. It closes the crash/error
     * window between gps_data persistence and the Reverb HTTP publish without
     * rewriting the large historical GPS table.
     */
    public function up(): void
    {
        if (app()->environment('testing') || config('database.connections.mysql_gps.database') === ':memory:') {
            return;
        }

        $schema = Schema::connection('mysql_gps');

        if ($schema->hasTable('gps_broadcast_outbox')) {
            return;
        }

        $schema->create('gps_broadcast_outbox', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_id', 64)->unique('gps_broadcast_outbox_event_id_unique');
            $table->string('trace_id', 128)->nullable()->index('gps_broadcast_outbox_trace_id_index');
            $table->string('imei', 20)->nullable()->index('gps_broadcast_outbox_imei_index');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('tractor_id');
            $table->char('payload_hash', 64)->nullable()->index('gps_broadcast_outbox_payload_hash_index');
            $table->longText('point_payload');
            $table->string('status', 32)->index('gps_broadcast_outbox_status_index');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable()->index('gps_broadcast_outbox_next_attempt_index');
            $table->dateTime('locked_until')->nullable()->index('gps_broadcast_outbox_locked_until_index');
            $table->text('last_error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at', 'id'], 'gps_broadcast_outbox_dispatch_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql_gps')->dropIfExists('gps_broadcast_outbox');
    }
};
