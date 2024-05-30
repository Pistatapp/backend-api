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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('goal')->nullable();
            $table->string('referrer')->nullable();
            $table->string('counselors')->nullable();
            $table->string('executors')->nullable();
            $table->string('statistical_counselors')->nullable();
            $table->string('implementation_location')->nullable();
            $table->text('used_materials')->nullable();
            $table->text('evaluation_criteria')->nullable();
            $table->text('description')->nullable();
            $table->string('start_date');
            $table->string('end_date')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
