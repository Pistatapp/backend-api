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
        Schema::table('valves', function (Blueprint $table) {
            $table->float('irrigated_area')->after('flow_rate')->comment('Area in hectares that this valve can irrigate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('valves', function (Blueprint $table) {
            $table->dropColumn('irrigated_area');
        });
    }
};
