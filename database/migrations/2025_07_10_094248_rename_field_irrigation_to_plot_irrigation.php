<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('field_irrigation', function (Blueprint $table) {
            $table->dropForeign(['field_id']);
        });

        // First update field_id values to corresponding plot_id
        $fieldIrrigations = DB::table('field_irrigation')->get();
        foreach ($fieldIrrigations as $fieldIrrigation) {
            $plot = DB::table('plots')
                ->where('field_id', $fieldIrrigation->field_id)
                ->orderBy('id')
                ->first();

            if ($plot) {
                DB::table('field_irrigation')
                    ->where('id', $fieldIrrigation->id)
                    ->update(['field_id' => $plot->id]);
            }
        }

        // Rename the table to irrigation_plot (Laravel convention for many-to-many)
        Schema::rename('field_irrigation', 'irrigation_plot');

        // Rename the column
        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->renameColumn('field_id', 'plot_id');
        });

        // Add new foreign key
        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->foreign('plot_id')->references('id')->on('plots')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key
        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->dropForeign(['plot_id']);
        });

        // Rename the column back
        Schema::table('irrigation_plot', function (Blueprint $table) {
            $table->renameColumn('plot_id', 'field_id');
        });

        // Rename the table back
        Schema::rename('irrigation_plot', 'field_irrigation');

        // Add back original foreign key
        Schema::table('field_irrigation', function (Blueprint $table) {
            $table->foreign('field_id')->references('id')->on('fields')->onDelete('cascade');
        });
    }
};
