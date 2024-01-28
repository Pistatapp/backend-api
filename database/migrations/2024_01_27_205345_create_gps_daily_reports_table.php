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
        Schema::create('gps_daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trucktor_id')->constrained();
            $table->float('travel_distance');
            $table->unsignedBigInteger('work_duration');
            $table->integer('stoppage_count');
            $table->unsignedBigInteger('stoppage_duration');
            $table->float('average_speed');
            $table->float('max_speed');
            $table->float('efficiency');
            $table->date('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gps_daily_reports');
    }
};
