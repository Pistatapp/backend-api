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
        Schema::table('labours', function (Blueprint $table) {
            $table->boolean('attendence_tracking_enabled')->default(false)->after('overtime_hourly_wage');
            $table->string('imei')->nullable()->after('attendence_tracking_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('labours', function (Blueprint $table) {
            $table->dropColumn('attendence_tracking_enabled');
            $table->dropColumn('imei');
        });
    }
};
