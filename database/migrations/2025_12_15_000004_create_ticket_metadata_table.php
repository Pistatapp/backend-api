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
        Schema::create('ticket_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->onDelete('cascade');
            $table->text('error_message')->nullable();
            $table->text('error_trace')->nullable();
            $table->string('page_path')->nullable();
            $table->string('app_version')->nullable();
            $table->string('device_model')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('ticket_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_metadata');
    }
};

