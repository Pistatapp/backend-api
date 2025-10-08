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
        // Step 1: First, expand the ENUM to include BOTH old and new values
        Schema::table('tractor_tasks', function (Blueprint $table) {
            $table->enum('status', [
                'not_started', 'not_done', 'in_progress', 'done',  // Old values
                'stopped'  // New value
            ])->default('not_started')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'stopped' status and convert any stopped tasks back to in_progress
        DB::table('tractor_tasks')
            ->where('status', 'stopped')
            ->update(['status' => 'in_progress']);

        // Revert ENUM to previous values
        Schema::table('tractor_tasks', function (Blueprint $table) {
            $table->enum('status', ['not_started', 'not_done', 'in_progress', 'done'])
                ->default('not_started')
                ->change();
        });
    }
};
