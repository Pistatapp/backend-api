<?php

namespace Database\Seeders;

use App\Models\Labour;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class FixLaboursWorkType extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Labour::chunk(100, function($labours) {
            foreach($labours as $labour) {
                if(!is_array($labour->work_days)) {
                    $labour->work_days = Arr::wrap($labour->work_days);
                }

                if($labour->work_type !== "shift_based") {
                    $labour->work_type = "shift_based";
                }

                $labour->save();
            }
        });
    }
}
