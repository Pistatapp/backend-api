<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volk_oil_sprays', function (Blueprint $table) {
            $table->timestamp('cold_requirement_checked_at')->nullable()->after('cold_requirement');
        });
    }

    public function down(): void
    {
        Schema::table('volk_oil_sprays', function (Blueprint $table) {
            $table->dropColumn('cold_requirement_checked_at');
        });
    }
};
