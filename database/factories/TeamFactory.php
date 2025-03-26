<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\Labour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'name' => $this->faker->company() . ' Team',
            'supervisor_id' => Labour::factory(),
        ];
    }
}
