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
        Schema::create('message_deletions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deleted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('deletion_type', ['for_me', 'for_everyone'])->default('for_me');
            $table->timestamps();

            $table->index('message_id');
            $table->index('deleted_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_deletions');
    }
};

