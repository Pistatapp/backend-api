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
        Schema::rename('blocks', 'plots');

        Schema::table('plots', function (Blueprint $table) {
            $table->decimal('area', 10, 2)->after('coordinates')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plots', function (Blueprint $table) {
            $table->dropColumn('area');
        });

        Schema::rename('plots', 'blocks');
    }
};
