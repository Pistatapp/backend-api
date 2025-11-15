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
        Schema::create('worker_daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('scheduled_hours', 5, 2)->default(0);
            $table->decimal('actual_work_hours', 5, 2)->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->integer('time_outside_zone')->default(0); // in minutes
            $table->decimal('productivity_score', 5, 2)->nullable(); // percentage
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->decimal('admin_added_hours', 5, 2)->default(0);
            $table->decimal('admin_reduced_hours', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            
            // One report per worker per day
            $table->unique(['employee_id', 'date'], 'unique_worker_date_report');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_daily_reports');
    }
};
