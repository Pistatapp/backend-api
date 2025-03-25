<?php

namespace Tests\Feature\Controllers;

use App\Models\Block;
use App\Models\Farm;
use App\Models\Field;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockControllerTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $farm;
    private $field;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user and farm
        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();
        $this->farm->users()->attach($this->user->id, [
            'role' => 'admin',
            'is_owner' => true,
        ]);

        // Create a field for testing
        $this->field = Field::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_list_blocks_of_a_field()
    {
        // Create some test blocks
        Block::factory()->count(3)->create([
            'field_id' => $this->field->id
        ]);

        $response = $this->getJson("/api/fields/{$this->field->id}/blocks");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'coordinates'
                    ]
                ]
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    /** @test */
    public function it_can_store_new_block()
    {
        $data = [
            'name' => 'Test Block',
            'coordinates' => [
                '35.123,51.456',
                '35.124,51.457',
                '35.125,51.458'
            ]
        ];

        $response = $this->postJson("/api/fields/{$this->field->id}/blocks", $data);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'coordinates'
                ]
            ]);

        $this->assertDatabaseHas('blocks', [
            'field_id' => $this->field->id,
            'name' => 'Test Block'
        ]);
    }

    /** @test */
    public function it_validates_store_request()
    {
        $response = $this->postJson("/api/fields/{$this->field->id}/blocks", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'coordinates'
            ]);
    }

    /** @test */
    public function it_can_show_block_details()
    {
        $block = Block::factory()->create([
            'field_id' => $this->field->id
        ]);

        $response = $this->getJson("/api/blocks/{$block->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'coordinates'
                ]
            ]);
    }

    /** @test */
    public function it_can_update_block()
    {
        $block = Block::factory()->create([
            'field_id' => $this->field->id
        ]);

        $data = [
            'name' => 'Updated Block',
            'coordinates' => [
                '35.123,51.456',
                '35.124,51.457',
                '35.125,51.458'
            ]
        ];

        $response = $this->putJson("/api/blocks/{$block->id}", $data);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'coordinates'
                ]
            ]);

        $this->assertDatabaseHas('blocks', [
            'id' => $block->id,
            'name' => 'Updated Block'
        ]);
    }

    /** @test */
    public function it_validates_update_request()
    {
        $block = Block::factory()->create([
            'field_id' => $this->field->id
        ]);

        $response = $this->putJson("/api/blocks/{$block->id}", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'coordinates'
            ]);
    }

    /** @test */
    public function it_can_delete_block()
    {
        $block = Block::factory()->create([
            'field_id' => $this->field->id
        ]);

        $response = $this->deleteJson("/api/blocks/{$block->id}");

        $response->assertStatus(410);
        $this->assertDatabaseMissing('blocks', ['id' => $block->id]);
    }
}
