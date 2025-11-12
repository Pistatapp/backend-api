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
        Schema::table('gps_metrics_calculations', function (Blueprint $table) {
            $table->json('timings')->nullable()->after('efficiency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_metrics_calculations', function (Blueprint $table) {
            $table->dropColumn('timings');
        });
    }
};
