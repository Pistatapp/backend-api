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
            $table->dropColumn(['start_date', 'end_date', 'name', 'description']);
            $table->date('date')->after('status');
            $table->time('start_time')->after('date');
            $table->time('end_time')->after('start_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tractor_tasks', function (Blueprint $table) {
            $table->dropColumn(['date', 'start_time', 'end_time']);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('name');
            $table->text('description');
        });
    }
};
