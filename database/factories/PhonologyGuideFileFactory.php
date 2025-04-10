<?php

namespace Database\Factories;

use App\Models\Pest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PhonologyGuideFile>
 */
class PhonologyGuideFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'phonologyable_type' => Pest::class,
            'phonologyable_id' => Pest::factory(),
            'created_by' => User::factory()
        ];
    }
}
