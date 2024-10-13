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
        Schema::rename('cold_requirement_notifications', 'volk_oil_sprays');
        Schema::table('volk_oil_sprays', function (Blueprint $table) {
            $table->dropColumn(['method', 'notified', 'notified_at', 'note']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('volk_oil_sprays', 'cold_requirement_notifications');
        Schema::table('cold_requirement_notifications', function (Blueprint $table) {
            $table->string('method')->nullable();
            $table->boolean('notified')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->string('note')->nullable();
        });
    }
};
