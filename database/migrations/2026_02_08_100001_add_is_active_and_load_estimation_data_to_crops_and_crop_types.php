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
        Schema::table('crops', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('created_by');
        });

        Schema::table('crop_types', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('created_by');
            $table->json('load_estimation_data')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crops', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('crop_types', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'load_estimation_data']);
        });
    }
};
