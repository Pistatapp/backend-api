<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Slider>
 */
class SliderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'page' => fake()->randomElement(['home', 'about', 'contact']),
            'is_active' => fake()->boolean(),
            'interval' => fake()->numberBetween(3, 10),
            'images' => [
                [
                    'file' => fake()->imageUrl(640, 480),
                    'sort_order' => 1,
                    'path' => 'slides/image1.jpg'
                ],
                [
                    'file' => fake()->imageUrl(640, 480),
                    'sort_order' => 2,
                    'path' => 'slides/image2.jpg'
                ],
                [
                    'file' => fake()->imageUrl(640, 480),
                    'sort_order' => 3,
                    'path' => 'slides/image3.jpg'
                ],
            ],
        ];
    }
}
