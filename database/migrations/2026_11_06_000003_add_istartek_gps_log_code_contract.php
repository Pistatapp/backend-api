<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('mysql_gps');

        if ($schema->hasTable('gps_ingest_events')) {
            $hasGpsLogCode = $schema->hasColumn('gps_ingest_events', 'gps_log_code');
            $hasRawIngressId = $schema->hasColumn('gps_ingest_events', 'raw_ingress_id');
            $existingColumns = [];
            foreach (['original_event_time_utc', 'event_time_tehran', 'event_date_jalali', 'event_timezone', 'source_protocol', 'time_provenance', 'deduplication_status'] as $column) {
                $existingColumns[$column] = $schema->hasColumn('gps_ingest_events', $column);
            }
            $schema->table('gps_ingest_events', function (Blueprint $table) use ($hasGpsLogCode, $hasRawIngressId, $existingColumns): void {
                if (! $hasGpsLogCode) {
                    $table->string('gps_log_code', 64)->nullable()->index('gps_ingest_events_gps_log_code_index');
                }
                if (! $hasRawIngressId) {
                    $table->string('raw_ingress_id', 128)->nullable()->unique('gps_ingest_events_raw_ingress_id_unique');
                }
                foreach (['original_event_time_utc', 'event_time_tehran', 'event_date_jalali', 'event_timezone', 'source_protocol', 'time_provenance', 'deduplication_status'] as $column) {
                    if (! $existingColumns[$column]) {
                        $table->string($column, 128)->nullable();
                    }
                }
            });
        }

        if ($schema->hasTable('gps_broadcast_outbox')) {
            $hasGpsLogCode = $schema->hasColumn('gps_broadcast_outbox', 'gps_log_code');
            $schema->table('gps_broadcast_outbox', function (Blueprint $table) use ($hasGpsLogCode): void {
                if (! $hasGpsLogCode) {
                    $table->string('gps_log_code', 64)->nullable()->unique('gps_broadcast_outbox_gps_log_code_unique');
                }
            });
        }

        if ($schema->hasTable('gps_data')) {
            $hasGpsLogCode = $schema->hasColumn('gps_data', 'gps_log_code');
            $hasRawIngressId = $schema->hasColumn('gps_data', 'raw_ingress_id');
            $schema->table('gps_data', function (Blueprint $table) use ($hasGpsLogCode, $hasRawIngressId): void {
                if (! $hasGpsLogCode) {
                    $table->string('gps_log_code', 64)->nullable()->index('gps_data_gps_log_code_index');
                }
                if (! $hasRawIngressId) {
                    $table->string('raw_ingress_id', 128)->nullable()->index('gps_data_raw_ingress_id_index');
                }
            });
        }

        if (! $schema->hasTable('gps_log_identities')) {
            $schema->create('gps_log_identities', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('gps_log_code', 64)->unique('gps_log_identities_code_unique');
                $table->char('vendor', 2);
                $table->string('imei', 20);
                $table->dateTime('device_event_utc', 6);
                $table->string('existing_event_id', 64)->nullable()->index('gps_log_identities_event_id_index');
                $table->char('canonical_payload_hash', 64)->nullable();
                $table->unsignedBigInteger('canonical_gps_data_id')->nullable()->index('gps_log_identities_gps_id_index');
                $table->string('status', 32)->default('PENDING')->index('gps_log_identities_status_index');
                $table->dateTime('first_seen_at', 6)->nullable();
                $table->dateTime('last_seen_at', 6)->nullable();
                $table->timestamps();
                $table->unique(['vendor', 'imei', 'device_event_utc'], 'gps_log_identities_vendor_imei_time_unique');
                $table->index(['imei', 'device_event_utc'], 'gps_log_identities_imei_time_index');
            });
        }

        if (! $schema->hasTable('gps_log_receipts')) {
            $schema->create('gps_log_receipts', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('raw_ingress_id', 128)->unique('gps_log_receipts_raw_ingress_unique');
                $table->string('gps_log_code', 64)->index('gps_log_receipts_code_index');
                $table->string('existing_event_id', 64)->nullable()->index('gps_log_receipts_event_id_index');
                $table->char('payload_hash', 64);
                $table->string('deduplication_status', 32);
                $table->text('conflict_reason')->nullable();
                $table->timestamps();
                $table->index(['gps_log_code', 'payload_hash'], 'gps_log_receipts_code_hash_index');
            });
        }
    }

    public function down(): void
    {
        // The identity and receipt ledgers are append-only forensic records.
    }
};
