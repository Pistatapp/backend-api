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
        Schema::table('tractors', function (Blueprint $table) {
            $table->boolean('is_in_repair_shop')
                ->default(false)
                ->after('is_working');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tractors', function (Blueprint $table) {
            $table->dropColumn('is_in_repair_shop');
        });
    }
};
