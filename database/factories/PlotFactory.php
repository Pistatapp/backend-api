<?php

namespace Database\Factories;

use App\Models\Field;
use App\Models\Plot;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlotFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Plot::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word,
            'coordinates' => [
                [$this->faker->latitude, $this->faker->longitude],
                [$this->faker->latitude, $this->faker->longitude],
                [$this->faker->latitude, $this->faker->longitude],
            ],
            'field_id' => Field::factory(),
        ];
    }
}
