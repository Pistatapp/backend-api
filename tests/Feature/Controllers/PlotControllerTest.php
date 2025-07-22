<?php

namespace Tests\Feature\Controllers;

use App\Models\Field;
use App\Models\Plot;
use App\Models\User;
use App\Models\Farm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PlotControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Field $field;

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
            'farm_id' => $this->farm->id,
        ]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_list_plots_of_a_field()
    {
        // Create some test plots
        Plot::factory()->count(3)->create([
            'field_id' => $this->field->id,
        ]);

        $response = $this->getJson("/api/fields/{$this->field->id}/plots");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'coordinates',
                        'field_id',
                        'created_at',
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_can_store_new_plot()
    {
        $data = [
            'name' => 'Test Plot',
            'coordinates' => [
                [1.23, 4.56],
                [7.89, 10.11],
            ],
        ];

        $response = $this->postJson("/api/fields/{$this->field->id}/plots", $data);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'coordinates',
                    'field_id',
                    'created_at',
                ],
            ]);

        $this->assertDatabaseHas('plots', [
            'field_id' => $this->field->id,
            'name' => 'Test Plot',
        ]);
    }

    #[Test]
    public function it_validates_required_fields_when_storing()
    {
        $response = $this->postJson("/api/fields/{$this->field->id}/plots", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'coordinates']);
    }

    #[Test]
    public function it_can_show_plot_details()
    {
        $plot = Plot::factory()->create([
            'field_id' => $this->field->id,
        ]);

        $response = $this->getJson("/api/plots/{$plot->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'coordinates',
                    'field_id',
                    'created_at',
                ],
            ]);
    }

    #[Test]
    public function it_can_update_plot()
    {
        $plot = Plot::factory()->create([
            'field_id' => $this->field->id,
        ]);

        $data = [
            'name' => 'Updated Plot',
            'coordinates' => [
                [1.23, 4.56],
                [7.89, 10.11],
            ],
        ];

        $response = $this->putJson("/api/plots/{$plot->id}", $data);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'coordinates',
                    'field_id',
                    'created_at',
                ],
            ]);

        $this->assertDatabaseHas('plots', [
            'id' => $plot->id,
            'name' => 'Updated Plot',
        ]);
    }

    #[Test]
    public function it_validates_required_fields_when_updating()
    {
        $plot = Plot::factory()->create([
            'field_id' => $this->field->id,
        ]);

        $response = $this->putJson("/api/plots/{$plot->id}", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'coordinates']);
    }

    #[Test]
    public function it_can_delete_plot()
    {
        $plot = Plot::factory()->create([
            'field_id' => $this->field->id,
        ]);

        $response = $this->deleteJson("/api/plots/{$plot->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('plots', ['id' => $plot->id]);
    }
}
