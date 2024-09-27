<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Labour;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $crop = \App\Models\Crop::factory()
            ->has(\App\Models\CropType::factory()->count(3))
            ->create();

        $user = \App\Models\User::factory()
            ->has(\App\Models\Profile::factory())
            ->create([
                'mobile' => '09369238614'
            ]);

        $farm = Farm::factory()->for($user)->create([
            'crop_id' => $crop->id,
        ]);
        Field::factory(5)->for($farm)->create([
            'crop_type_id' => \App\Models\CropType::inRandomOrder()->first()->id,
        ]);
        Labour::factory(5)->for($farm)->create();
        $pump = \App\Models\Pump::factory()->for($farm)->create();
        \App\Models\Valve::factory(5)->for($pump)->create();
    }
}
