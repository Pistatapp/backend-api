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
        Schema::create('tractor_efficiency_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tractor_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->decimal('total_efficiency', 5, 2)->default(0);
            $table->decimal('task_based_efficiency', 5, 2)->default(0);
            $table->timestamps();

            // Index for efficient querying
            $table->index(['tractor_id', 'date']);
            $table->unique(['tractor_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tractor_efficiency_charts');
    }
};
