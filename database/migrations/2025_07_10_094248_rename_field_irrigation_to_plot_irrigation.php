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
        Schema::rename('field_irrigation', 'irrigation_plot');

        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->renameColumn('field_id', 'plot_id');
            $table->dropForeign(['field_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->renameColumn('plot_id', 'field_id');
            $table->foreign('field_id')->references('id')->on('fields')->onDelete('cascade');
        });

        Schema::rename('irrigation_plot', 'field_irrigation');
    }
};
