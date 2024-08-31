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
        Schema::create('irrigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_id')->constrained();
            $table->foreignId('labour_id')->constrained();
            $table->date('date');
            $table->string('start_time');
            $table->string('end_time');
            $table->string('valves');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('irrigations');
    }
};
