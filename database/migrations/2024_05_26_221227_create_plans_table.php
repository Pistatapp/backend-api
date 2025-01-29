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
            $table->string('name');
            $table->text('goal');
            $table->string('referrer');
            $table->json('counselors');
            $table->json('executors');
            $table->json('statistical_counselors');
            $table->string('implementation_location');
            $table->text('used_materials');
            $table->text('evaluation_criteria');
            $table->text('description');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('farm_id');
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
