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
            $table->dropForeign(['field_id']);
            $table->renameColumn('field_id', 'farm_id');
            $table->dropColumn('valves');
            $table->foreign('farm_id')->references('id')->on('farms')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irrigations', function (Blueprint $table) {
            $table->dropForeign(['farm_id']);
            $table->renameColumn('farm_id', 'field_id');
            $table->json('valves')->nullable();
            $table->foreign('field_id')->references('id')->on('fields')->cascadeOnDelete();
        });
    }
};
