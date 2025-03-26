<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\User;
use App\Models\FarmPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class FarmPlanFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = FarmPlan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'name' => $this->faker->sentence(3),
            'goal' => $this->faker->paragraph,
            'referrer' => $this->faker->name,
            'counselors' => $this->faker->name,
            'executors' => $this->faker->name,
            'statistical_counselors' => $this->faker->name,
            'implementation_location' => $this->faker->city,
            'used_materials' => $this->faker->words(3, true),
            'evaluation_criteria' => $this->faker->sentence,
            'description' => $this->faker->paragraph,
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => 'pending',
            'created_by' => User::factory()
        ];
    }
}
