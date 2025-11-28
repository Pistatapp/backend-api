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
        Schema::table('irrigations', function (Blueprint $table) {
            // Drop unused columns if they exist
            if (Schema::hasColumn('irrigations', 'start_date')) {
                $table->dropColumn('start_date');
            }

            if (Schema::hasColumn('irrigations', 'end_date')) {
                $table->dropColumn('end_date');
            }

            if (Schema::hasColumn('irrigations', 'start_datetime')) {
                $table->dropColumn('start_datetime');
            }

            if (Schema::hasColumn('irrigations', 'end_datetime')) {
                $table->dropColumn('end_datetime');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irrigations', function (Blueprint $table) {
            // This migration only drops columns, so we can't restore them
            // The columns would need to be recreated in a previous migration if rollback is needed
        });
    }
};
