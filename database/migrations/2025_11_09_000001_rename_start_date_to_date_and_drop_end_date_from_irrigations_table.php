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
            $table->renameColumn('start_date', 'date');
            $table->dropColumn('end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irrigations', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('date');
            $table->renameColumn('date', 'start_date');
        });
    }
};

