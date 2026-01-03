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
        Schema::create('device_connection_requests', function (Blueprint $table) {
            $table->id();
            $table->string('mobile_number');
            $table->string('device_fingerprint')->unique();
            $table->json('device_info')->nullable(); // device model, OS version, app version
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index('status');
            $table->index('device_fingerprint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_connection_requests');
    }
};
