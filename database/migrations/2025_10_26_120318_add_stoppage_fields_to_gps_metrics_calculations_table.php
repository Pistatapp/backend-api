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
            $table->unsignedBigInteger('stoppage_duration_while_on')->default(0)->after('stoppage_duration');
            $table->unsignedBigInteger('stoppage_duration_while_off')->default(0)->after('stoppage_duration_while_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_metrics_calculations', function (Blueprint $table) {
            $table->dropColumn(['stoppage_duration_while_on', 'stoppage_duration_while_off']);
        });
    }
};
