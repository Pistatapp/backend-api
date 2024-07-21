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
        Schema::create('labours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('fname');
            $table->string('lname');
            $table->string('national_id');
            $table->string('mobile');
            $table->string('position');
            $table->string('work_type');
            $table->date('project_start_date')->nullable();
            $table->date('project_end_date')->nullable();
            $table->tinyInteger('work_days')->nullable();
            $table->tinyInteger('work_hours')->nullable();
            $table->time('start_work_time')->nullable();
            $table->time('end_work_time')->nullable();
            $table->bigInteger('salary')->nullable();
            $table->bigInteger('daily_salary')->nullable();
            $table->bigInteger('monthly_salary')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('labours');
    }
};
