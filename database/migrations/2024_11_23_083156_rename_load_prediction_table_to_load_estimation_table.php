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
        Schema::rename('load_prediction_tables', 'load_estimation_tables');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('load_estimation_tables', 'load_prediction_tables');
    }
};
