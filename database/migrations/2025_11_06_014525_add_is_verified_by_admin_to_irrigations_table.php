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
        Schema::table('irrigations', function (Blueprint $table) {
            $table->boolean('is_verified_by_admin')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irrigations', function (Blueprint $table) {
            $table->dropColumn('is_verified_by_admin');
        });
    }
};
