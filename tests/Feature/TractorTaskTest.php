<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Operation;

class TractorTaskTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private $user;
    private $tractor;
    private $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \App\Models\User::where('mobile', '09369238614')->first();
        $this->farm = $this->user->farms()->with('tractors')->first();
        $this->tractor = $this->farm->tractors->first();
    }

    /**
     * A basic feature test example.
     */
    public function test_user_can_create_tractor_tasks(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('tractors.tractor_tasks.store', $this->tractor), [
            'operation_id' => Operation::factory()->create()->id,
            'field_ids' => $this->farm->fields->pluck('id')->toArray(),
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
            'field_ids' => [3, 4],
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

        $response->assertGone();
    }
}
