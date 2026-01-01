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
        Schema::create('worker_shift_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('labour_id')->constrained('labours')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('work_shifts')->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->enum('status', ['scheduled', 'completed', 'missed', 'cancelled'])->default('scheduled');
            $table->timestamps();
            
            // Prevent duplicate shifts for same worker on same date
            $table->unique(['labour_id', 'shift_id', 'scheduled_date'], 'unique_worker_shift_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_shift_schedules');
    }
};
