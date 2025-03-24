<?php

namespace Database\Factories;

use App\Models\Field;
use App\Models\NutrientDiagnosisRequest;
use App\Models\NutrientSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating nutrient sample test data.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NutrientSample>
 */
class NutrientSampleFactory extends Factory
{
    protected $model = NutrientSample::class;

    /**
     * Define the model's default state.
     * Generates realistic nutrient level ranges for testing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nutrient_diagnosis_request_id' => NutrientDiagnosisRequest::factory(),
            'field_id' => Field::factory(),
            'field_area' => $this->faker->randomFloat(2, 100, 10000),
            'load_amount' => $this->faker->randomFloat(2, 10, 100),
            'nitrogen' => $this->faker->randomFloat(2, 0, 20),
            'phosphorus' => $this->faker->randomFloat(2, 0, 15),
            'potassium' => $this->faker->randomFloat(2, 0, 25),
            'calcium' => $this->faker->randomFloat(2, 0, 30),
            'magnesium' => $this->faker->randomFloat(2, 0, 10),
            'iron' => $this->faker->randomFloat(2, 0, 5),
            'copper' => $this->faker->randomFloat(2, 0, 2),
            'zinc' => $this->faker->randomFloat(2, 0, 3),
            'boron' => $this->faker->randomFloat(2, 0, 1),
        ];
    }
}
