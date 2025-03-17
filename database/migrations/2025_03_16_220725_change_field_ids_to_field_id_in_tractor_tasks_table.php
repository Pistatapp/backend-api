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
            $table->dropColumn('field_ids');
            $table->foreignId('field_id')->after('operation_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tractor_tasks', function (Blueprint $table) {
            $table->dropForeign(['field_id']);
            $table->dropColumn('field_id');
            $table->string('field_ids')->after('operation_id');
        });
    }
};
