<?php

namespace Tests\Feature\Controllers;

use App\Models\CropType;
use App\Models\Farm;
use App\Models\Field;
use App\Models\LoadEstimationTable;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoadEstimationControllerTest extends TestCase
{
    use RefreshDatabase;

    private CropType $cropType;
    private Farm $farm;
    private Field $field;
    private User $rootUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');

        $this->cropType = CropType::factory()->create();
        $this->farm = Farm::factory()->create();
        $this->field = Field::factory()->create([
            'farm_id' => $this->farm->id,
            'crop_type_id' => $this->cropType->id
        ]);
    }

    #[Test]
    public function it_can_show_load_estimation_table()
    {
        $loadEstimationTable = LoadEstimationTable::factory()->create([
            'crop_type_id' => $this->cropType->id,
            'rows' => [
                [
                    'condition' => 'excellent',
                    'fruit_cluster_weight' => 100,
                    'bud_to_fruit_conversion' => 0.8,
                    'estimated_to_actual_yield_ratio' => 0.9,
                    'tree_yield_weight_grams' => 1000,
                ]
            ]
        ]);

        $response = $this->actingAs($this->rootUser)
            ->getJson("/api/crop_types/{$this->cropType->id}/load_estimation");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['rows']])
            ->assertJson([
                'data' => [
                    'rows' => $loadEstimationTable->rows
                ]
            ]);
    }

    #[Test]
    public function it_can_update_load_estimation_table()
    {
        $rows = [
            [
                'condition' => 'excellent',
                'fruit_cluster_weight' => 100,
                'average_bud_count' => 50,
                'bud_to_fruit_conversion' => 0.8,
                'estimated_to_actual_yield_ratio' => 0.9,
                'tree_yield_weight_grams' => 1000,
                'tree_weight_kg' => 10,
                'tree_count' => 100,
                'total_garden_yield_kg' => 1000,
            ]
        ];

        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/crop_types/{$this->cropType->id}/load_estimation", [
                'rows' => $rows
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('load_estimation_tables', [
            'crop_type_id' => $this->cropType->id,
            'rows' => json_encode($rows)
        ]);
    }

    #[Test]
    public function it_validates_update_request()
    {
        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/crop_types/{$this->cropType->id}/load_estimation", [
                'rows' => [
                    [
                        'condition' => 'invalid',
                        'fruit_cluster_weight' => -1,
                        'bud_to_fruit_conversion' => -1,
                        'estimated_to_actual_yield_ratio' => -1,
                        'tree_yield_weight_grams' => -1,
                    ]
                ]
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'rows.0.condition',
                'rows.0.fruit_cluster_weight',
                'rows.0.bud_to_fruit_conversion',
                'rows.0.estimated_to_actual_yield_ratio',
                'rows.0.tree_yield_weight_grams',
            ]);
    }

    #[Test]
    public function it_can_estimate_yield()
    {
        $loadEstimationTable = LoadEstimationTable::factory()->create([
            'crop_type_id' => $this->cropType->id,
            'rows' => [
                [
                    'condition' => 'excellent',
                    'fruit_cluster_weight' => 100,
                    'bud_to_fruit_conversion' => 0.8,
                    'estimated_to_actual_yield_ratio' => 0.9,
                    'tree_yield_weight_grams' => 1000,
                ],
                [
                    'condition' => 'good',
                    'fruit_cluster_weight' => 80,
                    'bud_to_fruit_conversion' => 0.7,
                    'estimated_to_actual_yield_ratio' => 0.8,
                    'tree_yield_weight_grams' => 800,
                ],
                [
                    'condition' => 'normal',
                    'fruit_cluster_weight' => 60,
                    'bud_to_fruit_conversion' => 0.6,
                    'estimated_to_actual_yield_ratio' => 0.7,
                    'tree_yield_weight_grams' => 600,
                ],
                [
                    'condition' => 'bad',
                    'fruit_cluster_weight' => 40,
                    'bud_to_fruit_conversion' => 0.5,
                    'estimated_to_actual_yield_ratio' => 0.6,
                    'tree_yield_weight_grams' => 400,
                ]
            ]
        ]);

        $response = $this->actingAs($this->rootUser)
            ->postJson("/api/farms/{$this->farm->id}/load_estimation", [
                'field_id' => $this->field->id,
                'average_bud_count' => 50,
                'tree_count' => 100
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'excellent',
                    'good',
                    'normal',
                    'bad'
                ]
            ]);

        // Verify calculations for one condition (excellent)
        $excellentData = $response->json('data.excellent');
        $this->assertEquals(4, $excellentData['estimated_yield_per_tree_kg']); // 50 * 100 * 0.8 * 0.9 / 1000 ≈ 3.6 kg, rounded to 4
        $this->assertEquals(360, $excellentData['estimated_yield_total_kg']); // 3.6 * 100
    }

    #[Test]
    public function it_validates_estimate_request()
    {
        $response = $this->actingAs($this->rootUser)
            ->postJson("/api/farms/{$this->farm->id}/load_estimation", [
                'field_id' => 999999, // Non-existent field
                'average_bud_count' => -1,
                'tree_count' => -1
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'field_id',
                'average_bud_count',
                'tree_count'
            ]);
    }
}
