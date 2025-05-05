<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Row;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RowControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private $field;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user and a field for testing
        $this->user = User::factory()->create();
        $farm = Farm::factory()->create();
        $farm->users()->attach($this->user->id, ['role' => 'admin', 'is_owner' => true]);
        $this->field = Field::factory()->create([
            'farm_id' => $farm->id,
        ]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_list_rows_of_a_field()
    {
        Row::factory()->count(3)->create(['field_id' => $this->field->id]);

        $response = $this->getJson("/api/fields/{$this->field->id}/rows");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'name', 'coordinates'
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_can_create_rows_for_a_field()
    {
        $data = [
            'rows' => [
                [
                    'name' => 'Row 1',
                    'coordinates' => ['35.123,51.456', '35.124,51.457']
                ],
                [
                    'name' => 'Row 2',
                    'coordinates' => ['35.125,51.458', '35.126,51.459']
                ]
            ]
        ];

        $response = $this->postJson("/api/fields/{$this->field->id}/rows", $data);

        $response->assertOk();

        $this->assertDatabaseHas('rows', ['name' => 'Row 1', 'field_id' => $this->field->id]);
        $this->assertDatabaseHas('rows', ['name' => 'Row 2', 'field_id' => $this->field->id]);
    }

    #[Test]
    public function it_can_show_a_row()
    {
        $row = Row::factory()->create(['field_id' => $this->field->id]);

        $response = $this->getJson("/api/rows/{$row->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'coordinates'
                ]
            ]);
    }

    #[Test]
    public function it_can_delete_a_row()
    {
        $row = Row::factory()->create(['field_id' => $this->field->id]);

        $response = $this->deleteJson("/api/rows/{$row->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('rows', ['id' => $row->id]);
    }
}
