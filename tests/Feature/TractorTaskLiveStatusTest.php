<?php

namespace Tests\Feature;

use App\Events\TractorTaskStatusChanged;
use App\Models\Field;
use App\Models\Operation;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Models\User;
use App\Services\TractorTaskService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TractorTaskLiveStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_current_task_is_resolved_again_when_the_time_enters_the_task_window(): void
    {
        $tractor = Tractor::factory()->create();
        $task = $this->createTask($tractor, '10:00:00', '12:00:00');
        $service = app(TractorTaskService::class);

        $this->assertNull($service->getCurrentTask(
            Carbon::parse('2026-06-20 09:59:59'),
            $tractor
        ));

        $this->assertSame(
            $task->id,
            $service->getCurrentTask(Carbon::parse('2026-06-20 10:00:00'), $tractor)?->id
        );
    }

    public function test_live_gps_status_transitions_from_outside_to_in_progress(): void
    {
        Event::fake();

        $tractor = Tractor::factory()->create();
        $task = $this->createTask($tractor, '10:00:00', '12:00:00');
        $service = app(TractorTaskService::class);
        $timestamp = Carbon::parse('2026-06-20 10:15:00');

        $service->updateTaskStatus($task, false, $timestamp);
        $this->assertSame('stopped', $task->fresh()->status);

        $service->updateTaskStatus($task->fresh(), true, $timestamp->addMinute());

        $this->assertSame('in_progress', $task->fresh()->status);
        Event::assertDispatched(TractorTaskStatusChanged::class, function (TractorTaskStatusChanged $event) use ($task) {
            return $event->task->id === $task->id
                && $event->status === 'in_progress'
                && $event->isInZone === true;
        });
    }

    private function createTask(Tractor $tractor, string $startTime, string $endTime): TractorTask
    {
        $field = Field::factory()->create(['farm_id' => $tractor->farm_id]);

        $task = TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'operation_id' => Operation::factory()->create()->id,
            'created_by' => User::factory()->create()->id,
            'date' => '2026-06-20',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => 'not_started',
        ]);

        $task->taskableItems()->delete();
        $task->syncTaskableItems(Field::class, [$field->id]);

        return $task->fresh();
    }
}
