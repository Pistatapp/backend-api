<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\User;
use App\Models\NutrientDiagnosisRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating nutrient diagnosis request test data.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NutrientDiagnosisRequest>
 */
class NutrientDiagnosisRequestFactory extends Factory
{
    protected $model = NutrientDiagnosisRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'farm_id' => Farm::factory(),
            'status' => $this->faker->randomElement(['pending', 'completed']),
            'response_description' => $this->faker->optional()->paragraph(),
            'response_attachment' => $this->faker->optional()->filePath(),
        ];
    }

    /**
     * Indicate that the request is pending.
     *
     * @return static
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'response_description' => null,
            'response_attachment' => null,
        ]);
    }

    /**
     * Indicate that the request is completed.
     *
     * @return static
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'response_description' => $this->faker->paragraph(),
            'response_attachment' => 'nutrient-diagnosis/report.pdf',
        ]);
    }
}
