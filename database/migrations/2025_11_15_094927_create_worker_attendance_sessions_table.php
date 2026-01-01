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
        Schema::create('worker_attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('labour_id')->constrained('labours')->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('entry_time');
            $table->dateTime('exit_time')->nullable();
            $table->integer('total_in_zone_duration')->default(0); // in minutes
            $table->integer('total_out_zone_duration')->default(0); // in minutes
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
            $table->timestamps();
            
            // One session per worker per day
            $table->unique(['labour_id', 'date'], 'unique_worker_date_session');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_attendance_sessions');
    }
};
