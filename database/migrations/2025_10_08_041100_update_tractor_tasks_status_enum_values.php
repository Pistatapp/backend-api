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
                'pending', 'started', 'finished',           // Old values
                'not_started', 'not_done', 'in_progress', 'done'  // New values
            ])->default('pending')->change();
        });

        // Step 2: Now update existing data to new values
        DB::table('tractor_tasks')
            ->where('status', 'pending')
            ->update(['status' => 'not_started']);

        DB::table('tractor_tasks')
            ->where('status', 'started')
            ->update(['status' => 'in_progress']);

        DB::table('tractor_tasks')
            ->where('status', 'finished')
            ->update(['status' => 'done']);

        // Step 3: Finally, change the ENUM to only include new values
        Schema::table('tractor_tasks', function (Blueprint $table) {
            $table->enum('status', ['not_started', 'not_done', 'in_progress', 'done'])
                ->default('not_started')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert data to old values
        DB::table('tractor_tasks')
            ->where('status', 'not_started')
            ->update(['status' => 'pending']);

        DB::table('tractor_tasks')
            ->where('status', 'in_progress')
            ->update(['status' => 'started']);

        DB::table('tractor_tasks')
            ->whereIn('status', ['done', 'not_done'])
            ->update(['status' => 'finished']);

        // Revert the enum column
        Schema::table('tractor_tasks', function (Blueprint $table) {
            $table->enum('status', ['pending', 'started', 'finished'])
                ->default('pending')
                ->change();
        });
    }
};
