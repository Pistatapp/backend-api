<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the old foreign key constraint that references fields table
        if(DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        // Create a new table with correct foreign key constraints
        Schema::create('irrigation_plot_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plot_id')->constrained('plots')->onDelete('cascade');
            $table->foreignId('irrigation_id')->constrained('irrigations')->onDelete('cascade');
            $table->timestamps();
        });

        // Copy data from old table to new table
        DB::statement('INSERT INTO irrigation_plot_new (id, plot_id, irrigation_id, created_at, updated_at) SELECT id, plot_id, irrigation_id, created_at, updated_at FROM irrigation_plot');

        // Drop old table and rename new table
        Schema::drop('irrigation_plot');
        Schema::rename('irrigation_plot_new', 'irrigation_plot');

        if(DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This is a data migration, reversal is complex and not implemented
        // If needed, run migrate:fresh instead
    }
};
