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
        Schema::table('irrigations', function (Blueprint $table) {
            $table->text('note')->nullable()->after('end_time');
            $table->string('status')->default('pending')->after('note');
            $table->string('start_time')->change();
            $table->string('end_time')->change()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irrigations', function (Blueprint $table) {
            $table->dropColumn('note');
            $table->dropColumn('status');
            $table->string('start_time')->change();
            $table->string('end_time')->change();
        });
    }
};
