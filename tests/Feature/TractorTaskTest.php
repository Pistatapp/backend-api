<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Operation;
use App\Models\Farm;
use App\Models\Field;
use App\Models\Tractor;
use App\Models\User;
use App\Models\Driver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

class TractorTaskTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private $user;
    private $tractor;
    private $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->seed(RolePermissionSeeder::class);

        $this->user->assignRole('admin');

        $this->farm = Farm::factory()->create();

        Field::factory(3)->create([
            'farm_id' => $this->farm->id
        ]);

        $this->farm->users()->attach($this->user, [
            'role' => 'owner',
            'is_owner' => true
        ]);

        $this->tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        // Create and assign a driver to the tractor
        $driver = Driver::factory()->create([
            'farm_id' => $this->farm->id,
            'tractor_id' => $this->tractor->id,
        ]);

        // Ensure the driver is properly associated
        $this->tractor->refresh();

        Event::fake();
        Notification::fake();
    }



    /**
     * A basic feature test example.
     */
    public function test_user_can_create_tractor_tasks(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        $response->assertCreated();
    }

    /**
     * Test user can view tractor tasks and assert response structure.
     */
    public function test_user_can_view_tractor_tasks(): void
    {
        $this->actingAs($this->user);

        // Create test data
        $operation = Operation::factory()->create();
        $field = $this->farm->fields->first();

        // Create multiple tasks to test pagination and structure
        $tasks = \App\Models\TractorTask::factory(3)->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'created_by' => $this->user->id,
            'status' => 'pending',
            'data' => [
                'consumed_water' => 100,
                'consumed_fertilizer' => 50,
                'operation_area' => 25.5,
                'workers_count' => 3
            ]
        ]);

        $response = $this->getJson(route('tractors.tractor_tasks.index', $this->tractor));

        $response->assertOk();

        // Assert response structure
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'taskable' => [
                        'id',
                        'name',
                        'coordinates'
                    ],
                    'date',
                    'start_time',
                    'end_time',
                    'status',
                    'consumed_water',
                    'consumed_fertilizer',
                    'operation_area',
                    'workers_count',
                    'created_at'
                ]
            ],
            'links',
            'meta'
        ]);

        // Assert response data
        $responseData = $response->json('data');
        $this->assertCount(3, $responseData);

        // Assert first task structure
        $firstTask = $responseData[0];
        $this->assertIsInt($firstTask['id']);
        $this->assertIsString($firstTask['date']);
        $this->assertIsString($firstTask['start_time']);
        $this->assertIsString($firstTask['end_time']);
        $this->assertIsString($firstTask['status']);
        $this->assertEquals('pending', $firstTask['status']);

        // Assert taskable data
        $this->assertArrayHasKey('taskable', $firstTask);
        $this->assertIsInt($firstTask['taskable']['id']);
        $this->assertIsString($firstTask['taskable']['name']);

        // Assert data fields
        $this->assertEquals(100, $firstTask['consumed_water']);
        $this->assertEquals(50, $firstTask['consumed_fertilizer']);
        $this->assertEquals(25.5, $firstTask['operation_area']);
        $this->assertEquals(3, $firstTask['workers_count']);

        // Assert pagination structure
        $this->assertArrayHasKey('links', $response->json());
        $this->assertArrayHasKey('meta', $response->json());
    }

    /**
     * Test user can view tractor tasks filtered by date and assert response structure.
     */
    public function test_user_can_view_tractor_tasks_filtered_by_date(): void
    {
        $this->actingAs($this->user);

        // Create test data
        $operation = Operation::factory()->create();
        $field = $this->farm->fields->first();
        $specificDate = '1403/12/07';

        // Create tasks for specific date
        $tasksForDate = \App\Models\TractorTask::factory(2)->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'created_by' => $this->user->id,
            'date' => jalali_to_carbon($specificDate),
            'status' => 'pending',
            'data' => [
                'consumed_water' => 75,
                'operation_area' => 15.0
            ]
        ]);

        // Create a task for different date (should not be returned)
        \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'created_by' => $this->user->id,
            'date' => jalali_to_carbon('1403/12/08'),
            'status' => 'pending'
        ]);

        $response = $this->getJson(route('tractors.tractor_tasks.index', $this->tractor) . "?date={$specificDate}");

        $response->assertOk();

        // Assert response structure (same as index but without pagination)
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'taskable' => [
                        'id',
                        'name',
                        'coordinates'
                    ],
                    'date',
                    'start_time',
                    'end_time',
                    'status',
                    'consumed_water',
                    'operation_area',
                    'created_at'
                ]
            ]
        ]);

        // Assert response data
        $responseData = $response->json('data');
        $this->assertCount(2, $responseData);

        // Assert all tasks are for the specified date
        foreach ($responseData as $task) {
            $this->assertEquals($specificDate, $task['date']);
            $this->assertEquals('pending', $task['status']);
            $this->assertEquals(75, $task['consumed_water']);
            $this->assertEquals(15.0, $task['operation_area']);
        }

        // Assert no pagination for date-filtered results
        $this->assertArrayNotHasKey('links', $response->json());
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    /**
     * Test user can view a single tractor task.
     */
    public function test_user_can_view_single_tractor_task(): void
    {
        $this->actingAs($this->user);

        $task = \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson(route('tractor_tasks.show', $task));

        $response->assertOk();
    }

    /**
     * Test user can update a tractor task.
     */
    public function test_user_can_update_tractor_task(): void
    {
        $this->actingAs($this->user);

        $task = \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->putJson(route('tractor_tasks.update', $task), [
            'operation_id' => Operation::factory()->create()->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '08:00',
            'end_time' => '11:00',
        ]);

        $response->assertOk();
    }

    /**
     * Test user can delete a tractor task.
     */
    public function test_user_can_delete_tractor_task(): void
    {
        $this->actingAs($this->user);

        $task = \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'status' => 'pending',
        ]);

        $response = $this->deleteJson(route('tractor_tasks.destroy', $task));

        $response->assertNoContent();
    }

    /**
     * Test user can store multiple non-overlapping tasks for the same date.
     */
    public function test_user_can_store_multiple_non_overlapping_tasks_for_the_same_date(): void
    {
        $this->actingAs($this->user);

        // Create first task (8:00 - 10:00)
        $task = \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'date' => jalali_to_carbon('1403/12/07'),
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        // Try to create second task on same date but different time (10:30 - 12:00)
        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '10:30',
            'end_time' => '12:00',
        ]);

        $response->assertCreated();

        // Try to create overlapping task (11:00 - 13:00) - should fail
        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '11:00',
            'end_time' => '13:00',
        ]);

        $response->assertUnprocessable();
    }

    /**
     * Test user can not select end time before start time.
     */
    public function test_user_cannot_select_end_time_before_start_time(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '10:00',
            'end_time' => '08:00',
        ]);

        $response->assertUnprocessable();
    }

    /**
     * Test tractor task status updates when its start/end time arrives
     * and when it is marked as finished.
     */
    public function test_tractor_task_status_updates_when_start_end_time_arrives_and_marked_as_finished(): void
    {
        $this->actingAs($this->user);

        $now = now();

        // Create a task starting now and ending in 1 minute
        $task = \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'date' => $now->format('Y-m-d'),
            'start_time' => $now->format('H:i'),
            'end_time' => $now->addMinute()->format('H:i'),
        ]);

        // Check if task status is pending
        $this->assertEquals('pending', $task->status);

        // Run the command to update task statuses
        $this->artisan('tractor:update-task-status');

        // Check if task status is started
        $task->refresh();
        $this->assertEquals('started', $task->status);

        // Move time forward by 1 minute to reach end time
        \Illuminate\Support\Facades\Date::setTestNow(now()->addMinute());

        // Run the command to update task statuses
        $this->artisan('tractor:update-task-status');

        // Check if task status is finished
        $task->refresh();
        $this->assertEquals('finished', $task->status);
    }

    /**
     * Test start/finish events are broadcasted when task status changes.
     */
    public function test_start_finish_events_are_broadcasted_when_task_status_changes(): void
    {
        $this->actingAs($this->user);

        $now = now();

        // Create a task starting now and ending in 1 minute
        $task = \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'date' => $now->format('Y-m-d'),
            'start_time' => $now->format('H:i'),
            'end_time' => $now->addMinute()->format('H:i'),
        ]);

        // Fake events
        \Illuminate\Support\Facades\Event::fake();

        // Run the command to update task statuses
        $this->artisan('tractor:update-task-status');

        // Check if start event was broadcasted
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\TractorTaskStatusChanged::class);

        // Move time forward by 1 minute to reach end time
        \Illuminate\Support\Facades\Date::setTestNow(now()->addMinute());

        // Run the command to update task statuses
        $this->artisan('tractor:update-task-status');

        // Check if finish event was broadcasted
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\TractorTaskStatusChanged::class);
    }

    /**
     * Test user can filter tractor tasks by date range.
     */
    public function test_user_can_filter_tractor_tasks_by_date_range(): void
    {
        $this->actingAs($this->user);

        // Create some test data
        $operation1 = Operation::factory()->create();
        $operation2 = Operation::factory()->create();
        $field1 = $this->farm->fields->first();
        $field2 = $this->farm->fields->last();

        // Create tasks with different dates
        \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation1->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field1->id,
            'date' => jalali_to_carbon('1403/12/07'),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'created_by' => $this->user->id,
        ]);

        \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation2->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field2->id,
            'date' => jalali_to_carbon('1403/12/08'),
            'start_time' => '14:00',
            'end_time' => '16:00',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson(route('tractor_tasks.filter'), [
            'start_date' => '1403/12/07', // Use a known working Jalali date
            'end_date' => '1403/12/08',   // Use a known working Jalali date
            'tractor_id' => $this->tractor->id,
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    /**
     * Test user can filter tractor tasks by fields.
     */
    public function test_user_can_filter_tractor_tasks_by_fields(): void
    {
        $this->actingAs($this->user);

        // Create some test data
        $operation1 = Operation::factory()->create();
        $operation2 = Operation::factory()->create();
        $field1 = $this->farm->fields->first();
        $field2 = $this->farm->fields->last();

        // Create tasks with different dates
        \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation1->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field1->id,
            'date' => jalali_to_carbon('1403/12/07'),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'created_by' => $this->user->id,
        ]);

        \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation2->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field2->id,
            'date' => jalali_to_carbon('1403/12/08'),
            'start_time' => '14:00',
            'end_time' => '16:00',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson(route('tractor_tasks.filter'), [
            'start_date' => '1403/12/07', // Use consistent Jalali dates
            'end_date' => '1403/12/08',   // Use consistent Jalali dates
            'tractor_id' => $this->tractor->id,
            'fields' => [$field1->id],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * Test user can filter tractor tasks by operations.
     */
    public function test_user_can_filter_tractor_tasks_by_operations(): void
    {
        $this->actingAs($this->user);

        // Create some test data
        $operation1 = Operation::factory()->create();
        $operation2 = Operation::factory()->create();
        $field1 = $this->farm->fields->first();
        $field2 = $this->farm->fields->last();

        // Create tasks with different dates
        \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation1->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field1->id,
            'date' => jalali_to_carbon('1403/12/07'),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'created_by' => $this->user->id,
        ]);

        \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation2->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field2->id,
            'date' => jalali_to_carbon('1403/12/08'),
            'start_time' => '14:00',
            'end_time' => '16:00',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson(route('tractor_tasks.filter'), [
            'start_date' => '1403/12/07', // Use consistent Jalali dates
            'end_date' => '1403/12/08',   // Use consistent Jalali dates
            'tractor_id' => $this->tractor->id,
            'operations' => [$operation1->id],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * Test user can filter tractor tasks with combined filters.
     */
    public function test_user_can_filter_tractor_tasks_with_combined_filters(): void
    {
        $this->actingAs($this->user);

        // Create some test data
        $operation1 = Operation::factory()->create();
        $operation2 = Operation::factory()->create();
        $field1 = $this->farm->fields->first();
        $field2 = $this->farm->fields->last();

        // Create tasks with different dates
        \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation1->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field1->id,
            'date' => jalali_to_carbon('1403/12/07'),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'created_by' => $this->user->id,
        ]);

        \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation2->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field2->id,
            'date' => jalali_to_carbon('1403/12/08'),
            'start_time' => '14:00',
            'end_time' => '16:00',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson(route('tractor_tasks.filter'), [
            'start_date' => '1403/12/07', // Use consistent Jalali dates
            'end_date' => '1403/12/08',   // Use consistent Jalali dates
            'tractor_id' => $this->tractor->id,
            'fields' => [$field1->id],
            'operations' => [$operation1->id],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * Test time overlap validation with various scenarios.
     */
    public function test_time_overlap_validation_scenarios(): void
    {
        $this->actingAs($this->user);

        // Create first task (8:00 - 10:00)
        $firstTask = \App\Models\TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'date' => jalali_to_carbon('1403/12/07'),
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        // Test 1: Task that starts exactly when previous ends (10:00 - 12:00) - should succeed
        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '10:00',
            'end_time' => '12:00',
        ]);
        $response->assertCreated();

        // Test 2: Task that ends exactly when next starts (6:00 - 8:00) - should succeed
        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '06:00',
            'end_time' => '08:00',
        ]);
        $response->assertCreated();

        // Test 3: Task that overlaps with first task (9:00 - 11:00) - should fail
        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);
        $response->assertUnprocessable();

        // Test 4: Task that is completely contained by first task (8:30 - 9:30) - should fail
        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '08:30',
            'end_time' => '09:30',
        ]);
        $response->assertUnprocessable();

        // Test 5: Task that completely contains first task (7:00 - 11:00) - should fail
        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '07:00',
            'end_time' => '11:00',
        ]);
        $response->assertUnprocessable();
    }
}
