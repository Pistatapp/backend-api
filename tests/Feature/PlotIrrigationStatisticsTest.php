<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Plot;
use App\Models\Row;
use App\Models\Tree;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlotIrrigationStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_plot_irrigation_statistics_counts_trees_from_field_rows_inside_plot(): void
    {
        $farm = Farm::factory()->create();
        $field = Field::factory()->create(['farm_id' => $farm->id]);
        $plot = Plot::factory()->create([
            'field_id' => $field->id,
            'coordinates' => [
                [35.0000, 51.0000],
                [35.0000, 51.0010],
                [35.0010, 51.0010],
                [35.0010, 51.0000],
            ],
        ]);
        $row = Row::factory()->create(['field_id' => $field->id]);
        Tree::factory()->create([
            'row_id' => $row->id,
            'location' => [35.0005, 51.0005],
        ]);
        Tree::factory()->create([
            'row_id' => $row->id,
            'location' => [35.0020, 51.0020],
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $farm->users()->attach($user->id, [
            'role' => 'operator',
            'is_owner' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/plots/{$plot->id}/irrigation-statistics");

        $response->assertOk()
            ->assertJsonPath('data.plot_name', $plot->name)
            ->assertJsonPath('data.trees_count', 1)
            ->assertJsonPath('data.successful_irrigations_count_last_30_days', 0)
            ->assertJsonPath('data.irrigation_area_ha', 0)
            ->assertJsonPath('data.total_volume_last_30_days', 0)
            ->assertJsonPath('data.total_volume_per_hectare_last_30_days', null)
            ->assertJsonPath('data.area_source', 'valve.irrigation_area');
    }
}
