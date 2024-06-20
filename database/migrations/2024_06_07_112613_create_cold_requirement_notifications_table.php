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
        Schema::create('cold_requirement_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->date('start_dt');
            $table->date('end_dt');
            $table->unsignedInteger('min_temp');
            $table->unsignedInteger('max_temp');
            $table->unsignedInteger('cold_requirement');
            $table->string('method');
            $table->text('note')->nullable();
            $table->boolean('notified')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cold_requirement_notifications');
    }
};
