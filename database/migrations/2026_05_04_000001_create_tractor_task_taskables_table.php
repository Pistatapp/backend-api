<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tractor_task_taskables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tractor_task_id')->constrained()->cascadeOnDelete();
            $table->morphs('taskable');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(
                ['tractor_task_id', 'taskable_type', 'taskable_id'],
                'tractor_task_taskables_task_unique'
            );
        });

        if (Schema::hasTable('tractor_tasks')) {
            $this->backfillFromTractorTasks();
        }
    }

    /**
     * @return void
     */
    private function backfillFromTractorTasks(): void
    {
        $now = now();
        DB::table('tractor_tasks')
            ->orderBy('id')
            ->whereNotNull('taskable_id')
            ->chunkById(200, function ($tasks) use ($now) {
                $inserts = [];
                foreach ($tasks as $task) {
                    $inserts[] = [
                        'tractor_task_id' => $task->id,
                        'taskable_type' => $task->taskable_type,
                        'taskable_id' => $task->taskable_id,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($inserts !== []) {
                    DB::table('tractor_task_taskables')->insert($inserts);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tractor_task_taskables');
    }
};
