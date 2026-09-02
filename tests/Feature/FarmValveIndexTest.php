<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Plot;
use App\Models\User;
use App\Models\Valve;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarmValveIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_farm_valves_endpoint_returns_only_valves_from_that_farm(): void
    {
        $farm = Farm::factory()->create();
        $otherFarm = Farm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);

        $farm->users()->attach($user->id, [
            'role' => 'operator',
            'is_owner' => false,
        ]);

        $farmPlot = Plot::factory()->create([
            'field_id' => Field::factory()->create(['farm_id' => $farm->id])->id,
        ]);
        $otherFarmPlot = Plot::factory()->create([
            'field_id' => Field::factory()->create(['farm_id' => $otherFarm->id])->id,
        ]);
        $farmValve = Valve::factory()->create(['plot_id' => $farmPlot->id]);
        Valve::factory()->create(['plot_id' => $otherFarmPlot->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/farms/{$farm->id}/valves");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'plot_id',
                    'name',
                    'location',
                    'is_open',
                    'irrigation_area',
                    'dripper_count',
                    'dripper_flow_rate',
                    'unique_id',
                    'plot',
                    'created_at',
                ]],
            ])
            ->assertJsonPath('data.0.id', $farmValve->id)
            ->assertJsonPath('data.0.name', $farmValve->name)
            ->assertJsonPath('data.0.plot_id', $farmPlot->id);
    }

    public function test_non_member_cannot_list_farm_valves(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/farms/{$farm->id}/valves");

        $response->assertForbidden();
    }
}
