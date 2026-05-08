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
        Schema::table('maintenance_reports', function (Blueprint $table) {
            $table->timestamp('repair_shop_entered_at')->nullable()->after('description');
            $table->timestamp('repair_shop_exited_at')->nullable()->after('repair_shop_entered_at');
            $table->decimal('next_maintenance_km', 10, 2)->nullable()->after('repair_shop_exited_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_reports', function (Blueprint $table) {
            $table->dropColumn([
                'repair_shop_entered_at',
                'repair_shop_exited_at',
                'next_maintenance_km',
            ]);
        });
    }
};
