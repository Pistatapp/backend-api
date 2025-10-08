<?php

namespace Tests\Unit\Services;

use App\Models\Field;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Services\TractorTaskService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TractorTaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tractor $tractor;
    private Field $field;
    private TractorTaskService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tractor = Tractor::factory()->create();
        $this->field = Field::factory()->create();
        $this->service = new TractorTaskService($this->tractor);
    }

    /** @test */
    public function it_returns_null_when_no_tasks_exist()
    {
        $task = $this->service->getCurrentTask();

        $this->assertNull($task);
    }

    /** @test */
    public function it_returns_task_when_report_time_is_within_task_time_range()
    {
        Carbon::setTestNow('2025-10-08 10:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $reportTime = Carbon::parse('2025-10-08 10:00:00');
        $currentTask = $this->service->getCurrentTask($reportTime);

        $this->assertNotNull($currentTask);
        $this->assertEquals($task->id, $currentTask->id);
    }

    /** @test */
    public function it_returns_null_when_report_time_is_before_task_start()
    {
        Carbon::setTestNow('2025-10-08 07:00:00');

        TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $reportTime = Carbon::parse('2025-10-08 07:00:00');
        $currentTask = $this->service->getCurrentTask($reportTime);

        $this->assertNull($currentTask);
    }

    /** @test */
    public function it_returns_null_when_report_time_is_after_task_end()
    {
        Carbon::setTestNow('2025-10-08 13:00:00');

        TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $reportTime = Carbon::parse('2025-10-08 13:00:00');
        $currentTask = $this->service->getCurrentTask($reportTime);

        $this->assertNull($currentTask);
    }

    /** @test */
    public function it_prioritizes_in_progress_task_over_not_started_task()
    {
        Carbon::setTestNow('2025-10-08 10:00:00');

        $task1 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $task2 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'in_progress',
        ]);

        $reportTime = Carbon::parse('2025-10-08 10:00:00');
        $currentTask = $this->service->getCurrentTask($reportTime);

        $this->assertNotNull($currentTask);
        $this->assertEquals($task2->id, $currentTask->id);
    }

    /** @test */
    public function it_returns_first_matching_task_when_multiple_overlap()
    {
        Carbon::setTestNow('2025-10-08 10:00:00');

        $task1 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $task2 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'not_started',
        ]);

        $reportTime = Carbon::parse('2025-10-08 10:00:00');
        $currentTask = $this->service->getCurrentTask($reportTime);

        $this->assertNotNull($currentTask);
        // Should return task1 as it starts earlier
        $this->assertEquals($task1->id, $currentTask->id);
    }

    /** @test */
    public function it_excludes_done_tasks()
    {
        Carbon::setTestNow('2025-10-08 10:00:00');

        TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'done',
        ]);

        $reportTime = Carbon::parse('2025-10-08 10:00:00');
        $currentTask = $this->service->getCurrentTask($reportTime);

        $this->assertNull($currentTask);
    }

    /** @test */
    public function it_excludes_not_done_tasks()
    {
        Carbon::setTestNow('2025-10-08 10:00:00');

        TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_done',
        ]);

        $reportTime = Carbon::parse('2025-10-08 10:00:00');
        $currentTask = $this->service->getCurrentTask($reportTime);

        $this->assertNull($currentTask);
    }

    /** @test */
    public function it_uses_current_time_when_no_report_time_provided()
    {
        Carbon::setTestNow('2025-10-08 10:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        // Don't provide report time, should use now()
        $currentTask = $this->service->getCurrentTask();

        $this->assertNotNull($currentTask);
        $this->assertEquals($task->id, $currentTask->id);
    }

    /** @test */
    public function it_handles_tasks_that_cross_midnight()
    {
        Carbon::setTestNow('2025-10-09 00:30:00'); // 12:30 AM next day

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '22:00', // 10 PM
            'end_time' => '02:00', // 2 AM next day
            'status' => 'not_started',
        ]);

        $reportTime = Carbon::parse('2025-10-09 00:30:00');
        $currentTask = $this->service->getCurrentTask($reportTime);

        $this->assertNotNull($currentTask);
        $this->assertEquals($task->id, $currentTask->id);
    }

    /** @test */
    public function it_returns_task_at_exact_start_time()
    {
        Carbon::setTestNow('2025-10-08 08:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $reportTime = Carbon::parse('2025-10-08 08:00:00');
        $currentTask = $this->service->getCurrentTask($reportTime);

        $this->assertNotNull($currentTask);
        $this->assertEquals($task->id, $currentTask->id);
    }

    /** @test */
    public function it_returns_task_at_exact_end_time()
    {
        Carbon::setTestNow('2025-10-08 12:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $reportTime = Carbon::parse('2025-10-08 12:00:00');
        $currentTask = $this->service->getCurrentTask($reportTime);

        $this->assertNotNull($currentTask);
        $this->assertEquals($task->id, $currentTask->id);
    }

    /** @test */
    public function it_returns_task_zone_for_valid_task()
    {
        $expectedCoordinates = [
            [36.0, 51.0],
            [36.0, 51.1],
            [36.1, 51.1],
            [36.1, 51.0],
        ];

        $field = Field::factory()->create([
            'coordinates' => $expectedCoordinates
        ]);

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $field->id,
        ]);

        $zone = $this->service->getTaskZone($task);

        $this->assertEquals($expectedCoordinates, $zone);
    }

    /** @test */
    public function it_returns_null_zone_for_null_task()
    {
        $zone = $this->service->getTaskZone(null);

        $this->assertNull($zone);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // Reset Carbon mock
        parent::tearDown();
    }
}

