<?php

namespace Database\Seeders;

use App\Models\Crop;
use App\Models\CropType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FarmSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $crops = Crop::factory(10)->has(CropType::factory())->create();
        $user = User::where('mobile', '09369238614')->first();
    }
}
