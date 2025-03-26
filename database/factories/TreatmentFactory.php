<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\Treatment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TreatmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Treatment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'name' => $this->faker->unique()->word(),
            'color' => $this->faker->hexColor(),
            'description' => $this->faker->sentence(),
        ];
    }
}
