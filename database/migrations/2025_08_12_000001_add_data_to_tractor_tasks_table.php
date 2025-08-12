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
        Schema::table('tractor_tasks', function (Blueprint $table) {
            // Store task-specific numeric details such as consumed water, fertilizer, etc.
            $table->json('data')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tractor_tasks', function (Blueprint $table) {
            $table->dropColumn('data');
        });
    }
};


