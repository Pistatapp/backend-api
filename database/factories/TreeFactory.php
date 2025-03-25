<?php

namespace Database\Factories;

use App\Models\Row;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tree>
 */
class TreeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'row_id' => Row::factory(),
            'name' => $this->faker->word(),
            'location' => [$this->faker->latitude(), $this->faker->longitude()],
            'unique_id' => $this->faker->unique()->regexify('[A-Za-z0-9]{15}'),
            'qr_code' => $this->faker->text()
        ];
    }
}
