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
        Schema::table('irrigation_plot', function (Blueprint $table) {
            // Drop the incorrect foreign key constraint that points plot_id to fields table
            // This constraint was left over from when the table was field_irrigation
            $table->dropForeign(['plot_id']);

            // Re-add the correct foreign key constraint pointing to plots table
            $table->foreign('plot_id')->references('id')->on('plots')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irrigation_plot', function (Blueprint $table) {
            // Revert the changes
            $table->dropForeign(['plot_id']);
            $table->foreign('plot_id')->references('id')->on('fields')->onDelete('cascade');
        });
    }
};
