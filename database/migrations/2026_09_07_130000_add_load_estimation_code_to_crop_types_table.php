<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crop_types', function (Blueprint $table) {
            $table->string('load_estimation_code', 64)
                ->nullable()
                ->unique()
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('crop_types', function (Blueprint $table) {
            $table->dropUnique('crop_types_load_estimation_code_unique');
            $table->dropColumn('load_estimation_code');
        });
    }
};
