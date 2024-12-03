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
        Schema::table('farm_plan_details', function (Blueprint $table) {
            $table->renameColumn('plan_id', 'farm_plan_id');
            $table->renameColumn('timar_id', 'treatment_id');
            $table->renameColumn('timarable_id', 'treatable_id');
            $table->renameColumn('timarable_type', 'treatable_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('farm_plan_details', function (Blueprint $table) {
            $table->renameColumn('farm_plan_id', 'plan_id');
            $table->renameColumn('treatment_id ', 'timar_id');
            $table->renameColumn('treatable_id', 'timarable_id');
            $table->renameColumn('treatable_type', 'timarable_type');
        });
    }
};
