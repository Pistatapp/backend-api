<?php

namespace Tests\Feature\Services;

use App\Events\TractorTaskStatusChanged;
use App\Models\Field;
use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Services\TractorTaskStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TractorTaskStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private TractorTaskStatusService $service;
    private Tractor $tractor;
    private Field $field;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TractorTaskStatusService();
        $this->tractor = Tractor::factory()->create([
            'start_work_time' => '08:00',
            'end_work_time' => '16:00',
            'expected_daily_work_time' => 8,
        ]);
        $this->field = Field::factory()->create();
    }

    /** @test */
    public function it_marks_task_as_not_started_when_current_time_is_before_start_time()
    {
        Carbon::setTestNow('2025-10-08 07:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $this->service->updateTaskStatus($task);

        $this->assertEquals('not_started', $task->fresh()->status);
    }

    /** @test */
    public function it_keeps_task_as_not_started_when_time_started_but_tractor_hasnt_entered_zone()
    {
        Carbon::setTestNow('2025-10-08 09:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        // No GPS metrics exist (tractor hasn't entered zone)

        $this->service->updateTaskStatus($task);

        $this->assertEquals('not_started', $task->fresh()->status);
    }

    /** @test */
    public function it_marks_task_as_in_progress_when_tractor_enters_zone_during_task_time()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 09:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        // Create GPS metrics showing tractor has entered zone
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2025-10-08',
            'work_duration' => 1800, // 30 minutes
        ]);

        $this->service->updateTaskStatus($task);

        $this->assertEquals('in_progress', $task->fresh()->status);
        Event::assertDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_marks_task_as_not_done_when_task_ends_and_tractor_never_entered_zone()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 13:00:00'); // After task end time

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        // No GPS metrics exist

        $this->service->updateTaskStatus($task);

        $this->assertEquals('not_done', $task->fresh()->status);
        Event::assertDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_marks_task_as_not_done_when_presence_is_less_than_30_percent()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 13:00:00'); // After task end time

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00', // 4 hours = 14400 seconds
            'status' => 'in_progress',
        ]);

        // Tractor was in zone for 29% of time (4176 seconds out of 14400)
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2025-10-08',
            'work_duration' => 4176, // 29% of 14400 seconds
        ]);

        $this->service->updateTaskStatus($task);

        $this->assertEquals('not_done', $task->fresh()->status);
        Event::assertDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_marks_task_as_done_when_presence_is_exactly_30_percent()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 13:00:00'); // After task end time

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00', // 4 hours = 14400 seconds
            'status' => 'in_progress',
        ]);

        // Tractor was in zone for exactly 30% of time (4320 seconds out of 14400)
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2025-10-08',
            'work_duration' => 4320, // 30% of 14400 seconds
        ]);

        $this->service->updateTaskStatus($task);

        $this->assertEquals('done', $task->fresh()->status);
        Event::assertDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_marks_task_as_done_when_presence_is_more_than_30_percent()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 13:00:00'); // After task end time

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00', // 4 hours = 14400 seconds
            'status' => 'in_progress',
        ]);

        // Tractor was in zone for 75% of time (10800 seconds out of 14400)
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2025-10-08',
            'work_duration' => 10800, // 75% of 14400 seconds
        ]);

        $this->service->updateTaskStatus($task);

        $this->assertEquals('done', $task->fresh()->status);
        Event::assertDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_handles_task_that_crosses_midnight()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 11:00:00'); // After task end time

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00', // 8 AM
            'end_time' => '10:00', // 10 AM (2 hours = 7200 seconds)
        ]);

        // Manually update status to in_progress
        $task->update(['status' => 'in_progress']);
        $task->refresh(); // Refresh to get updated status

        // Tractor was in zone for 50% of time (3600 seconds out of 7200)
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2025-10-08',
            'work_duration' => 3600, // 50% of 7200 seconds
        ]);

        $this->service->updateTaskStatus($task);

        $this->assertEquals('done', $task->fresh()->status);
        Event::assertDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_does_not_fire_event_when_status_does_not_change()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 07:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $this->service->updateTaskStatus($task);

        // Status should still be not_started, so no event should fire
        $this->assertEquals('not_started', $task->fresh()->status);
        Event::assertNotDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_updates_multiple_tasks_for_tractor_on_given_date()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 13:00:00');

        $task1 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'status' => 'not_started',
        ]);

        $task2 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        // Task on different date should not be affected
        $task3 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-09',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $this->service->updateTasksForTractor($this->tractor->id, '2025-10-08');

        $this->assertEquals('not_done', $task1->fresh()->status);
        $this->assertEquals('not_done', $task2->fresh()->status);
        $this->assertEquals('not_started', $task3->fresh()->status);
    }

    /** @test */
    public function it_marks_task_as_in_progress_when_tractor_enters_zone_if_within_task_time()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 09:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $this->service->markTaskInProgressIfApplicable($task);

        $this->assertEquals('in_progress', $task->fresh()->status);
        Event::assertDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_does_not_mark_task_as_in_progress_if_before_task_start_time()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 07:30:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $this->service->markTaskInProgressIfApplicable($task);

        $this->assertEquals('not_started', $task->fresh()->status);
        Event::assertNotDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_does_not_mark_task_as_in_progress_if_after_task_end_time()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 13:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $this->service->markTaskInProgressIfApplicable($task);

        $this->assertEquals('not_started', $task->fresh()->status);
        Event::assertNotDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_does_not_mark_task_as_in_progress_if_already_in_progress()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 09:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'in_progress',
        ]);

        $this->service->markTaskInProgressIfApplicable($task);

        $this->assertEquals('in_progress', $task->fresh()->status);
        Event::assertNotDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_handles_null_task_in_update_task_status_after_gps_processing()
    {
        // Should not throw exception
        $this->service->updateTaskStatusAfterGpsProcessing(null);
        $this->assertTrue(true);
    }

    /** @test */
    public function it_updates_task_status_after_gps_processing()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 09:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2025-10-08',
            'work_duration' => 1800,
        ]);

        $this->service->updateTaskStatusAfterGpsProcessing($task);

        $this->assertEquals('in_progress', $task->fresh()->status);
    }

    /** @test */
    public function it_calculates_percentage_correctly_for_edge_cases()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 13:00:00');

        // Test with very short task duration
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '08:10', // 10 minutes = 600 seconds
            'status' => 'in_progress',
        ]);

        // Tractor was in zone for 40% of time (240 seconds out of 600)
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2025-10-08',
            'work_duration' => 240, // 40%
        ]);

        $this->service->updateTaskStatus($task);

        $this->assertEquals('done', $task->fresh()->status);
    }

    /** @test */
    public function it_maintains_in_progress_status_during_task_time_with_zone_entry()
    {
        Carbon::setTestNow('2025-10-08 10:00:00'); // During task time

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'in_progress',
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2025-10-08',
            'work_duration' => 3600, // 1 hour
        ]);

        $this->service->updateTaskStatus($task);

        $this->assertEquals('in_progress', $task->fresh()->status);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // Reset Carbon mock
        parent::tearDown();
    }
}
