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
        Schema::create('attendance_trackings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('work_type'); // administrative or shift_based
            $table->json('work_days')->nullable(); // Array of work days
            $table->decimal('work_hours', 5, 2)->nullable(); // Decimal for precision
            $table->time('start_work_time')->nullable();
            $table->time('end_work_time')->nullable();
            $table->integer('hourly_wage');
            $table->integer('overtime_hourly_wage');
            $table->string('imei')->nullable();
            $table->boolean('attendance_tracking_enabled')->default(true);
            $table->timestamps();

            // Add unique constraint to ensure one record per user
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_trackings');
    }
};
