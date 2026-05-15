<?php

use App\Support\QrIdentity;
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
        foreach (['fields', 'rows', 'plots', 'farm_plans'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('unique_id')->nullable();
                $table->text('qr_code')->nullable();
            });
        }

        foreach (['fields', 'rows', 'plots', 'farm_plans', 'trees'] as $table) {
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $pair = QrIdentity::makeForTable($table);
                    DB::table($table)->where('id', $row->id)->update([
                        'unique_id' => $pair['unique_id'],
                        'qr_code' => $pair['qr_code'],
                    ]);
                }
            });
        }

        foreach (['fields', 'rows', 'plots', 'farm_plans', 'trees'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unique('unique_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['fields', 'rows', 'plots', 'farm_plans', 'trees'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropUnique(['unique_id']);
            });
        }

        foreach (['fields', 'rows', 'plots', 'farm_plans'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['unique_id', 'qr_code']);
            });
        }
    }
};
