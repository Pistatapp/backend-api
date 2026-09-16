<?php

namespace Tests\Feature;

use App\Models\CropType;
use App\Models\Farm;
use App\Models\Field;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadEstimationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_calculate_using_the_field_crop_type_code(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create(['is_active' => true, 'username' => 'load-estimation-user']);
        $farm->users()->attach($user->id, ['role' => 'operator', 'is_owner' => false]);
        $cropType = CropType::factory()->create([
            'name' => 'احمدآقایی',
            'crop_id' => $farm->crop_id,
            'load_estimation_code' => 'ahmad-aghaei',
        ]);
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'crop_type_id' => $cropType->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            "/api/farms/{$farm->id}/load-estimation",
            [
                'field_id' => $field->id,
                ...$this->validPayload($field->id),
            ]
        );

        $response->assertOk()
            ->assertJsonPath('data.cultivar.code', 'ahmad-aghaei')
            ->assertJsonPath('data.crop.id', $farm->crop_id)
            ->assertJsonPath('data.formula_version', '1.0')
            ->assertJsonPath('data.inputs.average_bud_count', 300)
            ->assertJsonPath('data.results.excellent.load_grams', 12282);
    }

    public function test_non_member_cannot_calculate_for_a_farm(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create(['is_active' => true, 'username' => 'non-member']);
        $cropType = CropType::factory()->create(['load_estimation_code' => 'akbari']);
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'crop_type_id' => $cropType->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            "/api/farms/{$farm->id}/load-estimation",
            $this->validPayload($field->id)
        );

        $response->assertForbidden();
    }

    public function test_field_from_another_farm_cannot_be_used(): void
    {
        $farm = Farm::factory()->create();
        $otherFarm = Farm::factory()->create();
        $user = User::factory()->create(['is_active' => true, 'username' => 'scope-user']);
        $farm->users()->attach($user->id, ['role' => 'operator', 'is_owner' => false]);
        $cropType = CropType::factory()->create(['load_estimation_code' => 'akbari']);
        $otherField = Field::factory()->create([
            'farm_id' => $otherFarm->id,
            'crop_type_id' => $cropType->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            "/api/farms/{$farm->id}/load-estimation",
            $this->validPayload($otherField->id)
        );

        $response->assertNotFound();
    }

    public function test_missing_cultivar_returns_structured_error(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create(['is_active' => true, 'username' => 'missing-cultivar']);
        $farm->users()->attach($user->id, ['role' => 'operator', 'is_owner' => false]);
        $field = Field::factory()->create(['farm_id' => $farm->id, 'crop_type_id' => null]);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            "/api/farms/{$farm->id}/load-estimation",
            $this->validPayload($field->id)
        );

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'CULTIVAR_REQUIRED_FOR_LOAD_ESTIMATION')
            ->assertJsonPath('field', 'crop_type_id');
    }

    public function test_missing_farm_crop_returns_structured_error(): void
    {
        $farm = Farm::factory()->create(['crop_id' => null]);
        $user = User::factory()->create(['is_active' => true, 'username' => 'missing-crop']);
        $farm->users()->attach($user->id, ['role' => 'operator', 'is_owner' => false]);
        $cropType = CropType::factory()->create(['load_estimation_code' => 'akbari']);
        $field = Field::factory()->create(['farm_id' => $farm->id, 'crop_type_id' => $cropType->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/farms/{$farm->id}/load-estimation", $this->validPayload($field->id));

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'CROP_REQUIRED_FOR_LOAD_ESTIMATION')
            ->assertJsonPath('field', 'crop_id');
    }

    public function test_cultivar_from_another_crop_is_rejected(): void
    {
        $farm = Farm::factory()->create();
        $otherCrop = \App\Models\Crop::factory()->create();
        $user = User::factory()->create(['is_active' => true, 'username' => 'mismatch-crop']);
        $farm->users()->attach($user->id, ['role' => 'operator', 'is_owner' => false]);
        $cropType = CropType::factory()->create(['crop_id' => $otherCrop->id, 'load_estimation_code' => 'akbari']);
        $field = Field::factory()->create(['farm_id' => $farm->id, 'crop_type_id' => $cropType->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/farms/{$farm->id}/load-estimation", $this->validPayload($field->id));

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'CULTIVAR_PRODUCT_MISMATCH_FOR_LOAD_ESTIMATION')
            ->assertJsonPath('field', 'crop_type_id');
    }

    public function test_client_cannot_override_crop_or_coefficients(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create(['is_active' => true, 'username' => 'coefficient-override']);
        $farm->users()->attach($user->id, ['role' => 'operator', 'is_owner' => false]);
        $cropType = CropType::factory()->create(['crop_id' => $farm->crop_id, 'load_estimation_code' => 'akbari']);
        $field = Field::factory()->create(['farm_id' => $farm->id, 'crop_type_id' => $cropType->id]);
        $payload = $this->validPayload($field->id) + [
            'crop_id' => 999999,
            'crop_type_id' => 999999,
            'bud_to_fruit_cluster_conversion' => 0.01,
            'estimated_to_actual_yield_ratio' => 0.01,
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/farms/{$farm->id}/load-estimation", $payload);

        $response->assertOk()
            ->assertJsonPath('data.cultivar.code', 'akbari')
            ->assertJsonPath('data.coefficients.bud_to_fruit_cluster_conversion', 0.82)
            ->assertJsonPath('data.coefficients.estimated_to_actual_yield_ratio', 0.95);
    }

    public function test_negative_null_and_oversized_inputs_are_rejected(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create(['is_active' => true, 'username' => 'invalid-inputs']);
        $farm->users()->attach($user->id, ['role' => 'operator', 'is_owner' => false]);
        $cropType = CropType::factory()->create(['crop_id' => $farm->crop_id, 'load_estimation_code' => 'akbari']);
        $field = Field::factory()->create(['farm_id' => $farm->id, 'crop_type_id' => $cropType->id]);

        foreach ([
            ['average_bud_count' => -1],
            ['tree_count' => -1],
            ['cluster_weight' => ['excellent' => -1, 'good' => 1, 'normal' => 1, 'bad' => 1]],
            ['average_bud_count' => null],
            ['tree_count' => null],
            ['cluster_weight' => ['excellent' => 10001, 'good' => 1, 'normal' => 1, 'bad' => 1]],
        ] as $override) {
            $response = $this->actingAs($user, 'sanctum')->postJson(
                "/api/farms/{$farm->id}/load-estimation",
                array_replace_recursive($this->validPayload($field->id), $override)
            );

            $response->assertStatus(422);
        }
    }

    private function validPayload(int $fieldId): array
    {
        return [
            'field_id' => $fieldId,
            'average_bud_count' => 300,
            'tree_count' => 100,
            'cluster_weight' => ['excellent' => 50, 'good' => 40, 'normal' => 30, 'bad' => 20],
        ];
    }
}
