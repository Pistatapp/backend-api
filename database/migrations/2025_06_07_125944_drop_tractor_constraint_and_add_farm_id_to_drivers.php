<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            // Handle foreign key dropping based on database driver
            if (Schema::hasColumn('drivers', 'tractor_id')) {
                $driverName = DB::getDriverName();

                if ($driverName === 'mysql') {
                    // For MySQL, drop the specific constraint name
                    $table->dropForeign('drivers_trucktor_id_foreign');
                } elseif ($driverName === 'sqlite') {
                    // For SQLite, use the column array method
                    try {
                        $table->dropForeign(['tractor_id']);
                    } catch (\Exception $e) {
                        // If it fails, continue - SQLite might not have the constraint
                    }
                }
            }

            // Make tractor_id nullable
            $table->unsignedBigInteger('tractor_id')->nullable()->change();

            // Add farm_id column
            $table->foreignId('farm_id')->nullable()->after('tractor_id')->constrained()->nullOnDelete();
        });

        // Update farm_id based on tractor's farm_id using Laravel's query builder
        $drivers = DB::table('drivers')
            ->whereNotNull('tractor_id')
            ->get();

        foreach ($drivers as $driver) {
            $tractor = DB::table('tractors')->find($driver->tractor_id);
            if ($tractor) {
                DB::table('drivers')
                    ->where('id', $driver->id)
                    ->update(['farm_id' => $tractor->farm_id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            // Make tractor_id required again
            $table->unsignedBigInteger('tractor_id')->nullable(false)->change();

            // Re-add foreign key constraint based on database driver
            $driverName = DB::getDriverName();

            if ($driverName === 'mysql') {
                // For MySQL, specify the constraint name
                $table->foreign('tractor_id', 'drivers_trucktor_id_foreign')
                      ->references('id')->on('tractors')->onDelete('cascade');
            } else {
                // For other databases (SQLite), use standard method
                $table->foreign('tractor_id')
                      ->references('id')->on('tractors')->onDelete('cascade');
            }

            // Drop farm_id column
            $table->dropForeign(['farm_id']);
            $table->dropColumn('farm_id');
        });
    }
};
