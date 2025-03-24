<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for creating nutrient samples table.
 * This table stores field nutrient analysis data including nutrient levels and field area.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates a new table for storing nutrient sample data with various nutrient measurements.
     */
    public function up(): void
    {
        Schema::create('nutrient_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nutrient_diagnosis_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('field_id')->constrained()->onDelete('cascade');
            $table->decimal('field_area', 10, 2);
            $table->decimal('load_amount', 10, 2);
            $table->decimal('nitrogen', 10, 2);
            $table->decimal('phosphorus', 10, 2);
            $table->decimal('potassium', 10, 2);
            $table->decimal('calcium', 10, 2);
            $table->decimal('magnesium', 10, 2);
            $table->decimal('iron', 10, 2);
            $table->decimal('copper', 10, 2);
            $table->decimal('zinc', 10, 2);
            $table->decimal('boron', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     * Drops the nutrient samples table.
     */
    public function down(): void
    {
        Schema::dropIfExists('nutrient_samples');
    }
};
