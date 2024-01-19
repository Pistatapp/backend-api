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
        Schema::create('pumps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('serial_number');
            $table->string('model');
            $table->string('manufacturer');
            $table->unsignedInteger('horsepower');
            $table->tinyInteger('phase');
            $table->unsignedInteger('voltage');
            $table->unsignedInteger('ampere');
            $table->unsignedInteger('rpm');
            $table->float('pipe_size');
            $table->float('debi');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_healthy')->default(true);
            $table->string('location');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pumps');
    }
};
