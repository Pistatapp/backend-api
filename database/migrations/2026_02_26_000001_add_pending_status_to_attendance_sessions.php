<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add 'pending' to attendance_sessions.status enum (was only 'in_progress', 'completed').
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE attendance_sessions MODIFY COLUMN status ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'in_progress'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Optionally convert any 'pending' back to 'in_progress' before reverting enum
        DB::table('attendance_sessions')->where('status', 'pending')->update(['status' => 'in_progress']);
        DB::statement("ALTER TABLE attendance_sessions MODIFY COLUMN status ENUM('in_progress', 'completed') NOT NULL DEFAULT 'in_progress'");
    }
};
