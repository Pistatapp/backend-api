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
        Schema::rename('trucktors', 'tractors');
        Schema::rename('trucktor_reports', 'tractor_reports');
        Schema::rename('trucktor_tasks', 'tractor_tasks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('tractors', 'trucktors');
        Schema::rename('tractor_reports', 'trucktor_reports');
        Schema::rename('tractor_tasks', 'trucktor_tasks');
    }
};
