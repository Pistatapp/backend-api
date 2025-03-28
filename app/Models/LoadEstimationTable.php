<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoadEstimationTable extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array[]
     */
    protected $fillable = ['crop_type_id', 'headers', 'rows'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected function casts()
    {
        return [
            'headers' => 'array',
            'rows' => 'array',
        ];
    }

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'headers' => '[
            "شرایط باغ",
            "وزن خوشه میوه در وضعیت های مختلف",
            "میانگین تعداد جوانه",
            "تبدیل جوانه گل به خوشه میوه",
            "ضریب تبدیل میزان محصول تخمینی به میزان محصول واقعی",
            "وزن بار درخت (گرم)",
            "وزن بار درخت (کیلوگرم)",
            "تعداد درخت",
            "وزن کل بار باغ (کیلوگرم)"
            ]',
        'rows' => '[
            {"condition": "excellent", "fruit_cluster_weight": "0", "average_bud_count": "0", "bud_to_fruit_conversion": "0", "tree_yield_weight_grams": "0", "estimated_to_actual_yield_ratio": "0", "tree_weight_kg": "0", "tree_count": "0", "total_garden_yield_kg": "0"},
            {"condition": "good", "fruit_cluster_weight": "0", "average_bud_count": "0", "bud_to_fruit_conversion": "0", "tree_yield_weight_grams": "0", "estimated_to_actual_yield_ratio": "0", "tree_weight_kg": "0", "tree_count": "0", "total_garden_yield_kg": "0"},
            {"condition": "normal", "fruit_cluster_weight": "0", "average_bud_count": "0", "bud_to_fruit_conversion": "0", "tree_yield_weight_grams": "0", "estimated_to_actual_yield_ratio": "0", "tree_weight_kg": "0", "tree_count": "0", "total_garden_yield_kg": "0"},
            {"condition": "bad", "fruit_cluster_weight": "0", "average_bud_count": "0", "bud_to_fruit_conversion": "0", "tree_yield_weight_grams": "0", "estimated_to_actual_yield_ratio": "0", "tree_weight_kg": "0", "tree_count": "0", "total_garden_yield_kg": "0"}
            ]'
    ];

    /**
     * Get the crop type that owns the load estimation table.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cropType()
    {
        return $this->belongsTo(CropType::class);
    }
}
