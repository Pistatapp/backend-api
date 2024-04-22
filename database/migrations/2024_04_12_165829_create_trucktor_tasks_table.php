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
        Schema::create('trucktor_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trucktor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operation_id')->constrained()->cascadeOnDelete();
            $table->string('field_ids');
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trucktor_tasks');
    }
};
