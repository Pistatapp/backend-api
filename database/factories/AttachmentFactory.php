<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->paragraph,
            'verified' => true,
            'user_id' => User::factory(),
        ];
    }
}
