<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->farm = Farm::factory()->create();

        $this->farm->users()->attach($this->user, [
            'is_owner' => true,
            'role' => 'admin'
        ]);
    }

    public function test_can_list_operations()
    {
        $operations = Operation::factory(3)->create([
            'farm_id' => $this->farm->id,
            'parent_id' => null
        ]);

        $child = Operation::factory()->create([
            'farm_id' => $this->farm->id,
            'parent_id' => $operations->first()->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/operations");

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'created_at'
                    ]
                ]
            ]);
    }

    public function test_can_create_operation()
    {
        $operationData = [
            'name' => 'Test Operation',
            'parent_id' => null
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/operations", $operationData);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'created_at'
                ]
            ]);

        $this->assertDatabaseHas('operations', [
            'name' => 'Test Operation',
            'farm_id' => $this->farm->id
        ]);
    }

    public function test_can_create_child_operation()
    {
        $parent = Operation::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $operationData = [
            'name' => 'Child Operation',
            'parent_id' => $parent->id
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/operations", $operationData);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'created_at'
                ]
            ]);

        $this->assertDatabaseHas('operations', [
            'name' => 'Child Operation',
            'farm_id' => $this->farm->id,
            'parent_id' => $parent->id
        ]);
    }

    public function test_can_show_operation()
    {
        $operation = Operation::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $child = Operation::factory()->create([
            'farm_id' => $this->farm->id,
            'parent_id' => $operation->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/operations/{$operation->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'created_at',
                    'children' => [
                        '*' => [
                            'id',
                            'name',
                            'created_at'
                        ]
                    ]
                ]
            ]);
    }

    public function test_can_update_operation()
    {
        $operation = Operation::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $updateData = [
            'name' => 'Updated Operation'
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/operations/{$operation->id}", $updateData);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'created_at'
                ]
            ]);

        $this->assertDatabaseHas('operations', [
            'id' => $operation->id,
            'name' => 'Updated Operation'
        ]);
    }

    public function test_can_delete_operation()
    {
        $operation = Operation::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/operations/{$operation->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('operations', [
            'id' => $operation->id
        ]);
    }

    public function test_cannot_access_operations_of_other_farms()
    {
        $otherUser = User::factory()->create();
        $otherFarm = Farm::factory()->create();

        $otherFarm->users()->attach($otherUser, [
            'is_owner' => true,
            'role' => 'admin'
        ]);

        $operation = Operation::factory()->create([
            'farm_id' => $otherFarm->id
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/operations/{$operation->id}")
            ->assertForbidden();

        $this->actingAs($this->user)
            ->putJson("/api/operations/{$operation->id}", ['name' => 'Hack'])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->deleteJson("/api/operations/{$operation->id}")
            ->assertForbidden();
    }
}
