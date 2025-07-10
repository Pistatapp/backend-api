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
        Schema::table('valves', function (Blueprint $table) {
            // Add new columns
            $table->foreignId('plot_id')->constrained()->onDelete('cascade');

            // Before dropping field_id, attach each valve to the first plot of its field (if any)
            DB::table('valves')->orderBy('id')->chunk(100, function ($valves) {
                foreach ($valves as $valve) {
                    if ($valve->field_id) {
                        $plot = DB::table('plots')->where('field_id', $valve->field_id)->orderBy('id')->first();
                        if ($plot) {
                            DB::table('valves')->where('id', $valve->id)->update(['plot_id' => $plot->id]);
                        }
                    }
                }
            });

            // Drop foreign key first
            $table->dropForeign(['field_id']);

            // Drop old columns
            $table->dropColumn(['flow_rate', 'field_id', 'irrigated_area']);

            $table->float('irrigation_area');
            $table->integer('dripper_count');
            $table->float('dripper_flow_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('valves', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['plot_id']);

            // Revert new columns
            $table->dropColumn(['plot_id', 'irrigation_area', 'dripper_count', 'dripper_flow_rate']);

            // Restore old columns
            $table->foreignId('field_id')->constrained()->onDelete('cascade');
            $table->integer('flow_rate');
            $table->float('irrigated_area');
        });
    }
};
