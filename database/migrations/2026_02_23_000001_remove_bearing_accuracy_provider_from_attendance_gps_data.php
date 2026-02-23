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
        Schema::table('attendance_gps_data', function (Blueprint $table) {
            $table->dropColumn(['bearing', 'accuracy', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_gps_data', function (Blueprint $table) {
            $table->decimal('bearing', 8, 2)->nullable()->after('speed');
            $table->decimal('accuracy', 8, 2)->nullable()->after('bearing');
            $table->string('provider')->nullable()->after('accuracy');
        });
    }
};
