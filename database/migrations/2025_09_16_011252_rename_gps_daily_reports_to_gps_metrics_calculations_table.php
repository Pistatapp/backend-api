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
        Schema::rename('gps_daily_reports', 'gps_metrics_calculations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('gps_metrics_calculations', 'gps_daily_reports');
    }
};
