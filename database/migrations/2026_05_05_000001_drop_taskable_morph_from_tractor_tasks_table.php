<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Task locations live only on tractor_task_taskables; drop legacy morph columns.
     */
    public function up(): void
    {
        Schema::table('tractor_tasks', function (Blueprint $table) {
            $table->dropMorphs('taskable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tractor_tasks', function (Blueprint $table) {
            $table->morphs('taskable');
        });
    }
};
