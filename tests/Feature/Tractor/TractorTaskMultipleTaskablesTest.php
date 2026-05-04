<?php

namespace Tests\Feature\Tractor;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Operation;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Models\User;
use App\Services\TractorTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Morilog\Jalali\Jalalian;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TractorTaskMultipleTaskablesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Farm $farm;

    private Tractor $tractor;

    private Operation $operation;

    private Field $fieldA;

    private Field $fieldB;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();

        Permission::firstOrCreate(['name' => 'assign-tractor-task']);
        Permission::firstOrCreate(['name' => 'view-defined-tractor-tasks']);

        $this->user = User::factory()->create([
            'is_active' => true,
        ]);
        $this->user->givePermissionTo(['assign-tractor-task', 'view-defined-tractor-tasks']);

        $this->farm = Farm::factory()->create();
        $this->farm->users()->attach($this->user->id);

        $this->tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        \App\Models\Driver::factory()->create([
            'tractor_id' => $this->tractor->id,
            'farm_id' => $this->farm->id,
        ]);

        $this->operation = Operation::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        $this->fieldA = Field::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'North Field',
        ]);
        $this->fieldB = Field::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'South Field',
        ]);
    }

    private function jalaliToday(): string
    {
        return Jalalian::fromCarbon(now())->format('Y/m/d');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validStorePayload(array $overrides = []): array
    {
        return array_merge([
            'operation_id' => $this->operation->id,
            'taskable_type' => 'field',
            'taskable_ids' => [$this->fieldA->id, $this->fieldB->id],
            'date' => $this->jalaliToday(),
            'start_time' => '06:10',
            'end_time' => '06:50',
        ], $overrides);
    }

    public function test_store_creates_pivot_rows_and_returns_taskables_json(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/tractors/{$this->tractor->id}/tractor_tasks", $this->validStorePayload());

        $response->assertCreated()
            ->assertJsonPath('data.taskables.0.id', $this->fieldA->id)
            ->assertJsonPath('data.taskables.1.id', $this->fieldB->id);

        $taskId = $response->json('data.id');
        $this->assertNotNull($taskId);

        $this->assertDatabaseHas('tractor_task_taskables', [
            'tractor_task_id' => $taskId,
            'taskable_type' => Field::class,
            'taskable_id' => $this->fieldA->id,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('tractor_task_taskables', [
            'tractor_task_id' => $taskId,
            'taskable_type' => Field::class,
            'taskable_id' => $this->fieldB->id,
            'sort_order' => 1,
        ]);

        $task = TractorTask::query()->findOrFail($taskId);
        $zones = app(TractorTaskService::class)->getTaskZones($task);
        $this->assertCount(2, $zones);
    }

    public function test_legacy_single_taskable_id_is_normalized(): void
    {
        $payload = [
            'operation_id' => $this->operation->id,
            'taskable_type' => 'field',
            'taskable_id' => $this->fieldA->id,
            'date' => $this->jalaliToday(),
            'start_time' => '07:10',
            'end_time' => '07:40',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/tractors/{$this->tractor->id}/tractor_tasks", $payload);

        $response->assertCreated();
        $taskId = $response->json('data.id');
        $this->assertDatabaseCount('tractor_task_taskables', 1);
        $this->assertDatabaseHas('tractor_task_taskables', [
            'tractor_task_id' => $taskId,
            'taskable_id' => $this->fieldA->id,
        ]);
    }

    public function test_store_rejects_duplicate_taskable_ids(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/tractors/{$this->tractor->id}/tractor_tasks", $this->validStorePayload([
                'start_time' => '08:00',
                'end_time' => '08:30',
                'taskable_ids' => [$this->fieldA->id, $this->fieldA->id],
            ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['taskable_ids.0', 'taskable_ids.1']);
    }

    public function test_update_syncs_taskable_items(): void
    {
        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/tractors/{$this->tractor->id}/tractor_tasks", $this->validStorePayload([
                'start_time' => '09:00',
                'end_time' => '09:45',
            ]));
        $create->assertCreated();
        $taskId = $create->json('data.id');

        $fieldC = Field::factory()->create(['farm_id' => $this->farm->id]);

        $updatePayload = [
            'operation_id' => $this->operation->id,
            'taskable_type' => 'field',
            'taskable_ids' => [$this->fieldB->id, $fieldC->id],
            'date' => $this->jalaliToday(),
            'start_time' => '09:00',
            'end_time' => '09:45',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/tractor_tasks/{$taskId}", $updatePayload);

        $response->assertOk()
            ->assertJsonPath('data.taskables.0.id', $this->fieldB->id)
            ->assertJsonPath('data.taskables.1.id', $fieldC->id);

        $this->assertDatabaseMissing('tractor_task_taskables', [
            'tractor_task_id' => $taskId,
            'taskable_id' => $this->fieldA->id,
        ]);
        $this->assertDatabaseHas('tractor_task_taskables', [
            'tractor_task_id' => $taskId,
            'taskable_id' => $fieldC->id,
        ]);
    }

    public function test_filter_tasks_matches_secondary_field_via_pivot(): void
    {
        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/tractors/{$this->tractor->id}/tractor_tasks", $this->validStorePayload([
                'start_time' => '10:00',
                'end_time' => '10:30',
            ]));
        $create->assertCreated();

        $start = Jalalian::fromCarbon(now()->startOfDay())->format('Y/m/d');
        $end = Jalalian::fromCarbon(now()->endOfDay())->format('Y/m/d');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/tractor_tasks/filter', [
                'tractor_id' => $this->tractor->id,
                'start_date' => $start,
                'end_date' => $end,
                'fields' => [$this->fieldB->id],
                'per_page' => 50,
            ]);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($create->json('data.id'), $ids);
    }

    public function test_show_includes_ordered_taskables(): void
    {
        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/tractors/{$this->tractor->id}/tractor_tasks", $this->validStorePayload([
                'start_time' => '11:00',
                'end_time' => '11:30',
            ]));
        $taskId = $create->json('data.id');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/tractor_tasks/{$taskId}");

        $response->assertOk()
            ->assertJsonCount(2, 'data.taskables')
            ->assertJsonPath('data.taskables.0.name', 'North Field')
            ->assertJsonPath('data.taskables.1.name', 'South Field');
    }
}
