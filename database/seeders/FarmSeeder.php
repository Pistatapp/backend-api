<?php

namespace Database\Seeders;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Labour;
use App\Models\User;
use App\Models\Pump;
use App\Models\Valve;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FarmSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::where('mobile', '09369238614')->first();

        for ($i = 0; $i < 3; $i++) {
            $farm = Farm::factory()
                ->hasAttached($user, [
                    'role' => 'admin',
                    'is_owner' => true,
                ])
                ->has(Labour::factory()->count(15))
                ->has(Field::factory()->count(4))
                ->has(Pump::factory())
                ->create();

            $fields = $farm->fields;
            $pump = $farm->pumps->first();

            $fields->each(function ($field) use ($pump) {
                Valve::factory()->count(4)->open()->create([
                    'pump_id' => $pump->id,
                    'field_id' => $field->id,
                ]);
            });
        }
    }
}
