<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for creating nutrient diagnosis requests table.
 * This table stores requests made by users for nutrient analysis of their fields.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates a new table for storing nutrient diagnosis requests.
     */
    public function up(): void
    {
        Schema::create('nutrient_diagnosis_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->text('response_description')->nullable();
            $table->string('response_attachment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     * Drops the nutrient diagnosis requests table.
     */
    public function down(): void
    {
        Schema::dropIfExists('nutrient_diagnosis_requests');
    }
};
