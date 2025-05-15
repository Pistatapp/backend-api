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

    #[Test]
    public function it_correctly_calculates_row_length()
    {
        // Create a row with known coordinates that are close to each other
        $lat1 = 35.123;
        $lon1 = 51.456;
        $lat2 = 35.124;
        $lon2 = 51.457;

        $row = Row::factory()->create([
            'field_id' => $this->field->id,
            'coordinates' => ["$lat1,$lon1", "$lat2,$lon2"]
        ]);

        // Get the row details from the API
        $response = $this->getJson("/api/rows/{$row->id}");

        $response->assertOk();

        // Get the calculated length from the response
        $length = $response->json('data.length');

        // For the coordinates provided (close coordinates), the length should be:
        // 1. Greater than zero
        $this->assertGreaterThan(0, $length, 'Length should be greater than zero');

        // 2. Likely to be around 0.14 km based on Haversine formula calculations
        $this->assertGreaterThan(0.13, $length, 'Length should be greater than 0.13 km');
        $this->assertLessThan(0.15, $length, 'Length should be less than 0.15 km');
    }
}
