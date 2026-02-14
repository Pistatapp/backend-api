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
        Schema::table('gps_devices', function (Blueprint $table) {
            $table->dropForeign(['farm_id']);
            $table->dropForeign(['labour_id']);
            $table->dropForeign(['approved_by']);
        });

        Schema::table('gps_devices', function (Blueprint $table) {
            $table->dropColumn([
                'mobile_number',
                'farm_id',
                'labour_id',
                'is_active',
                'approved_at',
                'approved_by',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_devices', function (Blueprint $table) {
            $table->string('mobile_number')->nullable()->after('device_fingerprint');
            $table->foreignId('farm_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('labour_id')->nullable()->after('tractor_id')->constrained('labours')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('sim_number');
            $table->timestamp('approved_at')->nullable()->after('is_active');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });
    }
};
