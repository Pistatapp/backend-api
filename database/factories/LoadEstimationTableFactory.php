<?php

namespace Database\Factories;

use App\Models\CropType;
use App\Models\LoadEstimationTable;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoadEstimationTableFactory extends Factory
{
    protected $model = LoadEstimationTable::class;

    public function definition()
    {
        return [
            'crop_type_id' => CropType::factory(),
            'rows' => [
                [
                    'condition' => 'excellent',
                    'fruit_cluster_weight' => 100,
                    'bud_to_fruit_conversion' => 0.8,
                    'estimated_to_actual_yield_ratio' => 0.9,
                    'tree_yield_weight_grams' => 1000,
                ]
            ]
        ];
    }
}
