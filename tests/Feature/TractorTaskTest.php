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
            'field_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        $response->assertCreated();
    }

    /**
     * Test user can view tractor tasks.
     */
    public function test_user_can_view_tractor_tasks(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson(route('tractors.tractor_tasks.index', $this->tractor));

        $response->assertOk();
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
            'field_id' => $this->farm->fields->first()->id,
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
            'field_id' => $this->farm->fields->first()->id,
            'date' => '1403/12/07',
            'start_time' => '10:30',
            'end_time' => '12:00',
        ]);

        $response->assertCreated();

        // Try to create overlapping task (11:00 - 13:00) - should fail
        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'field_id' => $this->farm->fields->first()->id,
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
            'field_id' => $this->farm->fields->first()->id,
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
}
