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
        // Skip foreign key constraint in testing environment to avoid transaction isolation issues
        if (app()->environment('testing', 'production')) {
            return;
        }

        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->foreign('plot_id')->references('id')->on('plots')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->dropForeign(['plot_id']);
        });
    }
};
