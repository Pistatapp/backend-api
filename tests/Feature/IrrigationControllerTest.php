<?php

namespace Tests\Feature;

use App\Models\Irrigation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Farm;
use App\Models\Labour;
use App\Models\Field;
use App\Models\Plot;
use App\Models\Valve;
use App\Models\Pump;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test class for basic irrigation CRUD operations
 */
class IrrigationControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private $farm;
    private $plots;
    private $valves;
    private $pump;
    private $labour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->farm = Farm::factory()
            ->hasAttached($this->user, [
                'role' => 'admin',
                'is_owner' => true
            ])
            ->create();

        $this->pump = Pump::factory()->create(['farm_id' => $this->farm->id]);
        $this->labour = Labour::factory()->create(['farm_id' => $this->farm->id]);

        // Create fields with plots
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);
        $this->plots = Plot::factory()->count(3)->create(['field_id' => $field->id]);

        $this->valves = Valve::factory(3)->create([
            'plot_id' => $this->plots->first()->id
        ]);

        $this->actingAs($this->user);
    }

    /**
     * Test if user can create irrigation.
     */
    #[Test]
    public function test_user_can_create_irrigation(): void
    {
        $this->withoutExceptionHandling();
        $response = $this->postJson(route('farms.irrigations.store', ['farm' => $this->farm]), [
            'labour_id' => $this->labour->id,
            'pump_id' => $this->pump->id,
            'date' => '1402/07/01',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'plots' => [$this->plots[0]->id, $this->plots[1]->id],
            'valves' => [$this->valves[0]->id, $this->valves[1]->id],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('irrigations', 1);
        $this->assertDatabaseHas('irrigations', [
            'pump_id' => $this->pump->id,
            'labour_id' => $this->labour->id,
        ]);
    }

    /**
     * Test if user can update irrigation.
     */
    #[Test]
    public function test_user_can_update_irrigation(): void
    {
        $irrigation = Irrigation::factory()->for($this->farm)->create([
            'created_by' => $this->user->id,
            'pump_id' => $this->pump->id,
            'labour_id' => $this->labour->id,
        ]);

        $response = $this->putJson(route('irrigations.update', $irrigation), [
            'labour_id' => $this->labour->id,
            'pump_id' => $this->pump->id,
            'date' => '1402/07/01',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'plots' => [$this->plots[0]->id, $this->plots[1]->id],
            'valves' => [$this->valves[0]->id, $this->valves[1]->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('irrigations', 1);
        $this->assertDatabaseHas('irrigations', [
            'pump_id' => $this->pump->id,
            'labour_id' => $this->labour->id,
        ]);
    }

    /**
     * Test if user can delete irrigation.
     */
    #[Test]
    public function test_user_can_delete_irrigation(): void
    {
        $irrigation = Irrigation::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'pump_id' => $this->pump->id,
        ]);

        $response = $this->deleteJson(route('irrigations.destroy', $irrigation));

        $response->assertStatus(204);
        $this->assertDatabaseCount('irrigations', 0);
    }

    /**
     * Test if user can view irrigation.
     */
    #[Test]
    public function test_user_can_view_irrigation(): void
    {
        $irrigation = Irrigation::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'pump_id' => $this->pump->id,
        ]);

        $response = $this->get(route('irrigations.show', $irrigation));

        $response->assertStatus(200);
    }

    /**
     * Test if user can view irrigations.
     */
    #[Test]
    public function test_user_can_list_irrigations(): void
    {
        // Create specific dates for testing
        $todayDate = now(); // Today's date
        $yesterdayDate = now()->subDay(); // Yesterday's date

        // Create irrigations for different dates and statuses
        $todayIrrigation = Irrigation::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'pump_id' => $this->pump->id,
            'date' => $todayDate,
            'status' => 'active',
        ]);
        $todayIrrigation->plots()->attach($this->plots->first());

        $yesterdayIrrigation = Irrigation::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'pump_id' => $this->pump->id,
            'date' => $yesterdayDate,
            'status' => 'finished',
        ]);
        $yesterdayIrrigation->plots()->attach($this->plots->first());

        $finishedTodayIrrigation = Irrigation::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'pump_id' => $this->pump->id,
            'date' => $todayDate,
            'status' => 'finished',
        ]);
        $finishedTodayIrrigation->plots()->attach($this->plots->first());

        // Test default behavior (today's irrigations, all statuses)
        $response = $this->getJson(route('farms.irrigations.index', ['farm' => $this->farm]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'date',
                        'start_time',
                        'end_time',
                        'status',
                        'duration',
                        'note',
                        'plots',
                        'created_by' => [
                            'id',
                            'name'
                        ],
                        'can' => [
                            'delete',
                            'update'
                        ]
                    ]
                ]
            ])
            ->assertJsonCount(2, 'data'); // Should return 2 irrigations for today

        // Test date filtering
        $yesterdayJalali = jdate($yesterdayDate)->format('Y/m/d'); // Convert to Jalali format
        $response = $this->getJson(route('farms.irrigations.index', [
            'farm' => $this->farm,
            'date' => $yesterdayJalali
        ]));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data'); // Should return 1 irrigation for yesterday

        // Test status filtering
        $response = $this->getJson(route('farms.irrigations.index', [
            'farm' => $this->farm,
            'status' => 'active'
        ]));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data'); // Should return 1 active irrigation for today

        $response = $this->getJson(route('farms.irrigations.index', [
            'farm' => $this->farm,
            'status' => 'finished'
        ]));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data'); // Should return 1 finished irrigation for today

        // Test combined date and status filtering
        $response = $this->getJson(route('farms.irrigations.index', [
            'farm' => $this->farm,
            'date' => $yesterdayJalali,
            'status' => 'finished'
        ]));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data'); // Should return 1 finished irrigation for yesterday

        // Test that creator and plots relationships are loaded
        $response = $this->getJson(route('farms.irrigations.index', ['farm' => $this->farm]));

        $response->assertStatus(200)
            ->assertJsonPath('data.0.created_by.name', $this->user->username)
            ->assertJsonPath('data.0.plots.0.id', $this->plots->first()->id);
    }

    /**
     * Test if user can get irrigations for a plot.
     */
    #[Test]
    public function test_user_can_get_irrigations_for_plot(): void
    {
        $plot = $this->plots->first();

        // Create some irrigations for the plot
        $irrigation = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'date' => now()->format('Y-m-d'),
            'pump_id' => $this->pump->id,
            'farm_id' => $this->farm->id,
        ]);
        $irrigation->plots()->attach($plot);

        $response = $this->getJson('api/plots/' . $plot->id . '/irrigations');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }
}
