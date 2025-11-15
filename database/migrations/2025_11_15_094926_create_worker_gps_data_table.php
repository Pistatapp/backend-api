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
        Schema::create('worker_gps_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->json('coordinate'); // {lat, lng, altitude}
            $table->decimal('speed', 8, 2)->nullable();
            $table->decimal('bearing', 8, 2)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->string('provider')->nullable();
            $table->dateTime('date_time');
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['employee_id', 'date_time']);
            $table->index('date_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_gps_data');
    }
};
