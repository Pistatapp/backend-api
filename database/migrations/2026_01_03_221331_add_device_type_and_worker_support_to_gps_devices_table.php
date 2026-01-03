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
            // Make imei nullable (mobile phones don't have IMEI)
            $table->string('imei')->nullable()->change();
            
            // Make sim_number nullable (not all devices have SIM)
            $table->string('sim_number')->nullable()->change();
            
            // Add device_type enum
            $table->enum('device_type', ['mobile_phone', 'personal_gps', 'tractor_gps'])->nullable()->after('id');
            
            // Add device_fingerprint for mobile phones (nullable, unique)
            $table->string('device_fingerprint')->nullable()->unique()->after('device_type');
            
            // Add mobile_number for mobile phones
            $table->string('mobile_number')->nullable()->after('device_fingerprint');
            
            // Add farm_id for orchard allocation
            $table->foreignId('farm_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            
            // Add labour_id for worker assignment
            $table->foreignId('labour_id')->nullable()->after('tractor_id')->constrained('labours')->nullOnDelete();
            
            // Add is_active boolean (default true)
            $table->boolean('is_active')->default(true)->after('sim_number');
            
            // Add approved_at timestamp
            $table->timestamp('approved_at')->nullable()->after('is_active');
            
            // Add approved_by foreign key to users
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });
        
        // Migrate existing tractor devices to have device_type = 'tractor_gps'
        \DB::table('gps_devices')
            ->whereNotNull('tractor_id')
            ->update(['device_type' => 'tractor_gps', 'is_active' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_devices', function (Blueprint $table) {
            $table->dropForeign(['farm_id']);
            $table->dropForeign(['labour_id']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'device_type',
                'device_fingerprint',
                'mobile_number',
                'farm_id',
                'labour_id',
                'is_active',
                'approved_at',
                'approved_by',
            ]);
            
            // Revert imei to NOT NULL (if needed)
            $driverName = \DB::getDriverName();
            if ($driverName !== 'sqlite') {
                $table->string('imei')->nullable(false)->change();
            }
        });
    }
};
