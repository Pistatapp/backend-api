<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the table - this will automatically drop all indexes and foreign keys
        Schema::dropIfExists('gps_reports');
    }

    /**
     * Reverse the migrations.
     *
     * Note: This migration permanently removes the gps_reports table.
     * Reversing would require recreating the entire table structure,
     * which is not recommended as this table is being removed.
     */
    public function down(): void
    {
        // This migration is not reversible as it permanently removes the gps_reports table.
        // If you need to restore this table, you would need to restore from a database backup
        // or recreate it using the original migration files.
    }
};
