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
        Schema::create('worker_monthly_payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('labour_id')->constrained('labours')->cascadeOnDelete();
            $table->tinyInteger('month');
            $table->smallInteger('year');
            $table->decimal('total_work_hours', 8, 2)->default(0);
            $table->decimal('total_required_hours', 8, 2)->default(0);
            $table->decimal('total_overtime_hours', 8, 2)->default(0);
            $table->bigInteger('base_wage_total')->default(0);
            $table->bigInteger('overtime_wage_total')->default(0);
            $table->bigInteger('additions')->default(0);
            $table->bigInteger('deductions')->default(0);
            $table->bigInteger('final_total')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            
            // One payroll per worker per month
            $table->unique(['labour_id', 'month', 'year'], 'unique_worker_month_year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_monthly_payrolls');
    }
};
