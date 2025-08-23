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
        Schema::table('tractor_tasks', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['field_id']);

            // Drop the field_id column
            $table->dropColumn('field_id');

            // Add polymorphic columns
            $table->morphs('taskable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tractor_tasks', function (Blueprint $table) {
            // Drop polymorphic columns
            $table->dropMorphs('taskable');

            // Re-add field_id column
            $table->unsignedBigInteger('field_id')->after('operation_id');

            // Add foreign key constraint
            $table->foreign('field_id')->references('id')->on('fields')->cascadeOnDelete();
        });
    }
};
