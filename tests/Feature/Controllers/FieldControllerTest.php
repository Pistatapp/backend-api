<?php

namespace Tests\Feature\Api\Controllers;

use App\Models\Farm;
use App\Models\Field;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldControllerTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $farm;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user and a farm for testing
        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();

        $this->farm->users()->attach($this->user->id, [
            'role' => 'admin',
            'is_owner' => true,
        ]);

        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_list_fields_of_a_farm()
    {
        Field::factory()->count(3)->create(['farm_id' => $this->farm->id]);

        $response = $this->getJson("/api/farms/{$this->farm->id}/fields");

        $response->assertOk();
    }

    /** @test */
    public function it_can_create_a_field()
    {
        $data = [
            'name' => 'Test Field',
            'coordinates' => ['35.123,51.456', '35.124,51.457', '35.125,51.458'],
            'center' => '35.124,51.457',
            'area' => 100.5,
        ];

        $response = $this->postJson("/api/farms/{$this->farm->id}/fields", $data);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'coordinates', 'center', 'area'
                ]
            ]);

        $this->assertDatabaseHas('fields', [
            'name' => 'Test Field',
            'farm_id' => $this->farm->id
        ]);
    }

    /** @test */
    public function it_can_update_a_field()
    {
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);

        $data = [
            'name' => 'Updated Field',
            'coordinates' => ['35.123,51.456', '35.124,51.457', '35.125,51.458'],
            'center' => '35.124,51.457',
            'area' => 200.5,
        ];

        $response = $this->putJson("/api/fields/{$field->id}", $data);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'coordinates', 'center', 'area'
                ]
            ]);

        $this->assertDatabaseHas('fields', [
            'id' => $field->id,
            'name' => 'Updated Field',
        ]);
    }

    /** @test */
    public function it_can_delete_a_field()
    {
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);

        $response = $this->deleteJson("/api/fields/{$field->id}");

        $response->assertStatus(410);

        $this->assertDatabaseMissing('fields', [
            'id' => $field->id
        ]);
    }
}
