<?php

namespace Database\Factories;

use App\Models\Labour;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Labour>
 */
class LabourFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Labour::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'name' => $this->faker->name,
            'personnel_number' => $this->faker->unique()->numerify('##########'),
            'mobile' => $this->faker->phoneNumber,
            'user_id' => null,
        ];
    }
}
