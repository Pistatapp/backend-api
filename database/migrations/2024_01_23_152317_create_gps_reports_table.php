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
        Schema::create('gps_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gps_device_id')->constrained()->onDelete('cascade');
            $table->string('imei');
            $table->string('latitude');
            $table->string('longitude');
            $table->unsignedInteger('speed');
            $table->boolean('status');
            $table->boolean('is_stopped')->default(false);
            $table->unsignedBigInteger('stoppage_time')->default(0);
            $table->boolean('is_starting_point')->default(false);
            $table->boolean('is_ending_point')->default(false);
            $table->dateTime('date_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gps_reports');
    }
};
