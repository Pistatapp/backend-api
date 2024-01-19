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
        Schema::create('trees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('row_id')->constrained()->onDelete('cascade');
            $table->string('name')->nullable();
            $table->string('product')->nullable();
            $table->string('location');
            $table->string('image')->nullable();
            $table->string('unique_id')->nullable();
            $table->text('qr_code')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trees');
    }
};
