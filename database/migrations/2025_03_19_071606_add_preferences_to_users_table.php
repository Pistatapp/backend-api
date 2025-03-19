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
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('password_expires_at');
        });

        Schema::table('farm_user', function (Blueprint $table) {
            $table->dropColumn('is_working_environment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });

        Schema::table('farm_user', function (Blueprint $table) {
            $table->boolean('is_working_environment')->default(false)->after('role');
        });
    }
};
