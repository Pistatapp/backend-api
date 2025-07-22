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
            // Drop foreign key constraints if they exist
            try {
                $table->dropForeign(['irrigation_id']);
            } catch (Exception $e) {
                // Foreign key might not exist
            }
            
            try {
                $table->dropForeign(['plot_id']);
            } catch (Exception $e) {
                // Foreign key might not exist
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->foreign('irrigation_id')->references('id')->on('irrigations')->onDelete('cascade');
            $table->foreign('plot_id')->references('id')->on('plots')->onDelete('cascade');
        });
    }
};
