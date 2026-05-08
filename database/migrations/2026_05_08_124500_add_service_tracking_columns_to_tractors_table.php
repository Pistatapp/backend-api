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
            $table->dateTime('last_service_at')
                ->nullable()
                ->after('last_activity');
            $table->dateTime('last_service_notified_at')
                ->nullable()
                ->after('last_service_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tractors', function (Blueprint $table) {
            $table->dropColumn(['last_service_at', 'last_service_notified_at']);
        });
    }
};
