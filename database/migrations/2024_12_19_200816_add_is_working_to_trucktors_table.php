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
        Schema::table('trucktors', function (Blueprint $table) {
            $table->boolean('is_working')->default(false)->after('expected_yearly_work_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trucktors', function (Blueprint $table) {
            $table->dropColumn('is_working');
        });
    }
};
