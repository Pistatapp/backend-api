<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Operation;
use App\Models\Labour;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FarmReportFactory extends Factory
{
    public function definition()
    {
        return [
            'farm_id' => Farm::factory(),
            'date' => $this->faker->date(),
            'operation_id' => Operation::factory(),
            'labour_id' => Labour::factory(),
            'description' => $this->faker->sentence(),
            'value' => $this->faker->randomFloat(2, 0, 100),
            'reportable_type' => 'App\Models\Field',
            'reportable_id' => Field::factory(),
            'created_by' => User::factory(),
            'verified' => $this->faker->boolean(),
        ];
    }
}
