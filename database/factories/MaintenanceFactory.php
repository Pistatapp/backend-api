<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\Maintenance;
use Illuminate\Database\Eloquent\Factories\Factory;

class MaintenanceFactory extends Factory
{
    protected $model = Maintenance::class;

    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'name' => $this->faker->words(3, true)
        ];
    }
}
