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
        Schema::rename('products', 'crops');
        Schema::rename('product_types', 'crop_types');
        Schema::table('crop_types', function (Blueprint $table) {
            $table->renameColumn('product_id', 'crop_id');
        });
        Schema::table('farms', function (Blueprint $table) {
            $table->renameColumn('product_id', 'crop_id');
        });
        Schema::table('fields', function (Blueprint $table) {
            $table->renameColumn('product_type_id', 'crop_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('crops', 'products');
        Schema::rename('crop_types', 'product_types');
        Schema::table('crop_types', function (Blueprint $table) {
            $table->renameColumn('crop_id', 'product_id');
        });
        Schema::table('farms', function (Blueprint $table) {
            $table->renameColumn('crop_id', 'product_id');
        });
        Schema::table('fields', function (Blueprint $table) {
            $table->renameColumn('crop_type_id', 'product_type_id');
        });
    }
};
