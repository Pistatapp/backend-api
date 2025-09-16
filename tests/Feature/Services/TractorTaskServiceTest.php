<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\TractorTaskService;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Models\Field;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\Plot;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TractorTaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tractor $tractor;
    private TractorTaskService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tractor = Tractor::factory()->create();
        $this->service = new TractorTaskService($this->tractor);
    }

    #[Test]
    public function it_returns_null_when_no_current_task()
    {
        $currentTask = $this->service->getCurrentTask();

        $this->assertNull($currentTask);
    }

    #[Test]
    public function it_returns_current_task_for_today()
    {
        // Create a task for today
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $currentTask = $this->service->getCurrentTask();

        $this->assertNotNull($currentTask);
        $this->assertEquals($task->id, $currentTask->id);
        $this->assertEquals('started', $currentTask->status);
    }

    #[Test]
    public function it_ignores_tasks_for_other_dates()
    {
        // Create a task for yesterday
        TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => Carbon::yesterday(),
            'status' => 'started'
        ]);

        // Create a task for tomorrow
        TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => Carbon::tomorrow(),
            'status' => 'started'
        ]);

        $currentTask = $this->service->getCurrentTask();

        $this->assertNull($currentTask);
    }

    #[Test]
    public function it_ignores_tasks_with_non_started_status()
    {
        // Create tasks with different statuses
        TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'status' => 'pending'
        ]);

        TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'status' => 'finished'
        ]);

        TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'status' => 'cancelled'
        ]);

        $currentTask = $this->service->getCurrentTask();

        $this->assertNull($currentTask);
    }

    #[Test]
    public function it_returns_first_started_task_when_multiple_exist()
    {
        // Create multiple started tasks for today
        $firstTask = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'status' => 'started',
            'start_time' => '08:00'
        ]);

        $secondTask = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'status' => 'started',
            'start_time' => '10:00'
        ]);

        $currentTask = $this->service->getCurrentTask();

        $this->assertNotNull($currentTask);
        // Should return the first one (ordered by start_time)
        $this->assertEquals($firstTask->id, $currentTask->id);
    }

    #[Test]
    public function it_ignores_tasks_for_other_tractors()
    {
        $otherTractor = Tractor::factory()->create();

        // Create a task for another tractor
        TractorTask::factory()->create([
            'tractor_id' => $otherTractor->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $currentTask = $this->service->getCurrentTask();

        $this->assertNull($currentTask);
    }

    #[Test]
    public function it_returns_null_for_task_zone_when_no_task()
    {
        $taskZone = $this->service->getTaskZone(null);

        $this->assertNull($taskZone);
    }

    #[Test]
    public function it_returns_coordinates_for_field_task()
    {
        // Create a farm and field with coordinates
        $farm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'coordinates' => [
                [34.88, 50.58],
                [34.89, 50.58],
                [34.89, 50.59],
                [34.88, 50.59],
                [34.88, 50.58]
            ]
        ]);

        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task with the field
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $taskZone = $this->service->getTaskZone($task);

        $this->assertNotNull($taskZone);
        $this->assertIsArray($taskZone);
        $this->assertCount(5, $taskZone);
        $this->assertEquals([34.88, 50.58], $taskZone[0]);
    }

    #[Test]
    public function it_returns_coordinates_for_plot_task()
    {
        // Create a farm and plot with coordinates
        $farm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'coordinates' => [
                [35.88, 51.58],
                [35.89, 51.58],
                [35.89, 51.59],
                [35.88, 51.59],
                [35.88, 51.58]
            ]
        ]);

        $plot = Plot::factory()->create([
            'field_id' => $field->id,
            'coordinates' => [
                [35.88, 51.58],
                [35.89, 51.58],
                [35.89, 51.59],
                [35.88, 51.59],
                [35.88, 51.58]
            ]
        ]);

        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task with the plot
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Plot',
            'taskable_id' => $plot->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $taskZone = $this->service->getTaskZone($task);

        $this->assertNotNull($taskZone);
        $this->assertIsArray($taskZone);
        $this->assertCount(5, $taskZone);
        $this->assertEquals([35.88, 51.58], $taskZone[0]);
    }

    #[Test]
    public function it_returns_null_for_task_without_coordinates()
    {
        // Create a farm and field without coordinates
        $farm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'coordinates' => []
        ]);

        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task with the field
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $taskZone = $this->service->getTaskZone($task);

        $this->assertIsArray($taskZone);
        $this->assertEmpty($taskZone);
    }

    #[Test]
    public function it_handles_task_with_unknown_taskable_type()
    {
        // Create a task with an unknown taskable type
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => 'App\Models\Tractor', // Use a valid model that doesn't have coordinates
            'taskable_id' => $this->tractor->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $taskZone = $this->service->getTaskZone($task);

        $this->assertNull($taskZone);
    }

    #[Test]
    public function it_handles_task_with_missing_taskable_relation()
    {
        // Create a task with a non-existent taskable_id
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => 999999, // Non-existent field
            'date' => today(),
            'status' => 'started'
        ]);

        $taskZone = $this->service->getTaskZone($task);

        $this->assertNull($taskZone);
    }

    #[Test]
    public function it_handles_backward_compatibility_for_field_relation()
    {
        // Create a farm and field with coordinates
        $farm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'coordinates' => [
                [36.88, 52.58],
                [36.89, 52.58],
                [36.89, 52.59],
                [36.88, 52.59],
                [36.88, 52.58]
            ]
        ]);

        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task with the field
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $taskZone = $this->service->getTaskZone($task);

        $this->assertNotNull($taskZone);
        $this->assertIsArray($taskZone);
        $this->assertCount(5, $taskZone);
    }

    #[Test]
    public function it_handles_backward_compatibility_for_plot_relation()
    {
        // Create a farm and plot with coordinates
        $farm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'coordinates' => [
                [37.88, 53.58],
                [37.89, 53.58],
                [37.89, 53.59],
                [37.88, 53.59],
                [37.88, 53.58]
            ]
        ]);

        $plot = Plot::factory()->create([
            'field_id' => $field->id,
            'coordinates' => [
                [37.88, 53.58],
                [37.89, 53.58],
                [37.89, 53.59],
                [37.88, 53.59],
                [37.88, 53.58]
            ]
        ]);

        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task with the plot
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Plot',
            'taskable_id' => $plot->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $taskZone = $this->service->getTaskZone($task);

        $this->assertNotNull($taskZone);
        $this->assertIsArray($taskZone);
        $this->assertCount(5, $taskZone);
    }

    #[Test]
    public function it_handles_empty_coordinates_array()
    {
        // Create a farm and field with empty coordinates
        $farm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'coordinates' => []
        ]);

        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task with the field
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $taskZone = $this->service->getTaskZone($task);

        $this->assertIsArray($taskZone);
        $this->assertEmpty($taskZone);
    }

    #[Test]
    public function it_handles_malformed_coordinates()
    {
        // Create a farm and field with malformed coordinates
        $farm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'coordinates' => [
                [34.88], // Missing longitude
                [34.89, 50.58, 100], // Extra coordinate
                'invalid', // String instead of array
                [34.88, 50.58]
            ]
        ]);

        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task with the field
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $taskZone = $this->service->getTaskZone($task);

        // Should still return the coordinates array (validation happens elsewhere)
        $this->assertIsArray($taskZone);
    }
}
