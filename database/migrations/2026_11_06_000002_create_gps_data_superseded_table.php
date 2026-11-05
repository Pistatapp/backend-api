<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('mysql_gps');
        if ($schema->hasTable('gps_data_superseded')) return;

        $schema->create('gps_data_superseded', function (Blueprint $table): void {
            $table->unsignedBigInteger('gps_data_id')->primary();
            $table->unsignedBigInteger('canonical_gps_data_id');
            $table->text('evidence');
            $table->string('batch_id', 128);
            $table->timestamps();
            $table->index('canonical_gps_data_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        // The additive supersession ledger is never dropped automatically.
    }
};
