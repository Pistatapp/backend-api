<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Field;
use App\Models\Row;
use App\Models\Tree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Database\Seeders\RolePermissionSeeder;

class TreeControllerTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $farm;
    private $row;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user and farm
        $this->user = User::factory()->create();

        $this->seed(RolePermissionSeeder::class);

        $this->user->assignRole('admin');

        $this->farm = Farm::factory()->create();
        $this->farm->users()->attach($this->user, [
            'role' => 'owner',
            'is_owner' => true
        ]);

        $field = Field::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        // Create a row for testing
        $this->row = Row::factory()->create([
            'field_id' => $field->id
        ]);

        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_list_trees_of_a_row()
    {
        Tree::factory()->count(3)->create([
            'row_id' => $this->row->id,
            'location' => ['35.123', '51.456']
        ]);

        $response = $this->getJson("/api/rows/{$this->row->id}/trees");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'row_id',
                        'location'
                    ]
                ]
            ]);
    }

    /** @test */
    public function it_can_store_a_new_tree()
    {
        Storage::fake('public');

        $data = [
            'name' => 'Test Tree',
            'crop' => 'Apple',
            'location' => '35.123,51.456',
            'image' => UploadedFile::fake()->image('tree.jpg')
        ];

        $response = $this->postJson("/api/rows/{$this->row->id}/trees", $data);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'location',
                    'image',
                    'unique_id',
                    'qr_code'
                ]
            ]);

        $this->assertDatabaseHas('trees', [
            'row_id' => $this->row->id,
            'name' => 'Test Tree'
        ]);
    }

    /** @test */
    public function it_validates_store_request()
    {
        $response = $this->postJson("/api/rows/{$this->row->id}/trees", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'crop',
                'location'
            ]);
    }

    /** @test */
    public function it_can_show_tree_details()
    {
        $tree = Tree::factory()->create([
            'row_id' => $this->row->id,
            'location' => ['35.123', '51.456']
        ]);

        $response = $this->getJson("/api/trees/{$tree->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'row_id',
                    'name',
                    'location',
                    'created_at'
                ]
            ]);
    }

    /** @test */
    public function it_can_update_a_tree()
    {
        $tree = Tree::factory()->create([
            'row_id' => $this->row->id,
            'location' => ['35.123', '51.456']
        ]);

        Storage::fake('public');

        $data = [
            'name' => 'Updated Tree',
            'location' => '35.124,51.457',
            'image' => UploadedFile::fake()->image('updated_tree.jpg')
        ];

        $response = $this->putJson("/api/trees/{$tree->id}", $data);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'location',
                    'image'
                ]
            ]);

        $this->assertDatabaseHas('trees', [
            'id' => $tree->id,
            'name' => 'Updated Tree'
        ]);
    }

    /** @test */
    public function it_validates_update_request()
    {
        $tree = Tree::factory()->create([
            'row_id' => $this->row->id,
            'location' => ['35.123', '51.456']
        ]);

        $response = $this->putJson("/api/trees/{$tree->id}", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'location'
            ]);
    }

    /** @test */
    public function it_can_batch_store_trees()
    {
        $data = [
            'trees' => [
                [
                    'name' => 'Tree 1',
                    'location' => '35.123,51.456'
                ],
                [
                    'name' => 'Tree 2',
                    'location' => '35.124,51.457'
                ]
            ]
        ];

        $response = $this->postJson("/api/rows/{$this->row->id}/trees/batch_store", $data);

        $response->assertNoContent();

        $this->assertDatabaseHas('trees', [
            'row_id' => $this->row->id,
            'name' => 'Tree 1'
        ]);
        $this->assertDatabaseHas('trees', [
            'row_id' => $this->row->id,
            'name' => 'Tree 2'
        ]);
    }

    /** @test */
    public function it_validates_batch_store_request()
    {
        $response = $this->postJson("/api/rows/{$this->row->id}/trees/batch_store", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['trees']);
    }

    /** @test */
    public function it_can_delete_a_tree()
    {
        $tree = Tree::factory()->create([
            'row_id' => $this->row->id,
            'location' => ['35.123', '51.456']
        ]);

        $response = $this->deleteJson("/api/trees/{$tree->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('trees', ['id' => $tree->id]);
    }
}
