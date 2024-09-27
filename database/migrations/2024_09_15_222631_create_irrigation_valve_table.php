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
        Schema::create('irrigation_valve', function (Blueprint $table) {
            $table->id();
            $table->foreignId('irrigation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('valve_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('closed');
            $table->string('opened_at')->nullable();
            $table->string('closed_at')->nullable();
            $table->bigInteger('duration')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('irrigation_valve');
    }
};
