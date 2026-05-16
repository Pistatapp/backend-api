<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nutrient_diagnosis_requests', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('nutrient_diagnosis_requests', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('farm_id');
        });

        DB::table('nutrient_diagnosis_requests')
            ->where('status', 'completed')
            ->update(['status' => 'approved']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nutrient_diagnosis_requests', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('nutrient_diagnosis_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'completed'])->default('pending')->after('farm_id');
        });
    }
};
