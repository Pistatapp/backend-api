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
        Schema::table('labour', function (Blueprint $table) {
            $table->integer('farm_id')->unsigned()->index()->nullable()->after('id');
            $table->dropForeign(['team_id']);
            $table->integer('team_id')->unsigned()->index()->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('labour', function (Blueprint $table) {
            $table->dropColumn('farm_id');
            $table->integer('team_id')->unsigned()->index()->change();
        });
    }
};
