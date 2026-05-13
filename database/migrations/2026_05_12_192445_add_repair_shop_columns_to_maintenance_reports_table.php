<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Columns repair_shop_entered_at / repair_shop_exited_at are added in
 * 2026_05_04_100000_add_shop_times_and_next_maintenance_km_to_maintenance_reports_table.
 * This migration is retained as a no-op for deployments that already ran the earlier duplicate version.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
