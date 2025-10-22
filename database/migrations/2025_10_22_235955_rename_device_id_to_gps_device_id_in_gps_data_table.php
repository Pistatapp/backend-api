<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gps_data', function (Blueprint $table) {
            $table->renameColumn('device_id', 'gps_device_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_data', function (Blueprint $table) {
            $table->renameColumn('gps_device_id', 'device_id');
        });
    }
};
