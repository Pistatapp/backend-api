<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoadPredictionTable extends Model
{
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
            ["عالی", "67", "0.89", "0.92", "0", "0", "0", "0", "0"],
            ["خوب", "57", "0.89", "0.92", "0", "0", "0", "0", "0"],
            ["متوسط", "51", "0.89", "0.92", "0", "0", "0", "0", "0"],
            ["بد", "39.3", "0.89", "0.92", "0", "0", "0", "0", "0"]]',
    ];

    /**
     * Get the crop type that owns the load prediction table.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cropType()
    {
        return $this->belongsTo(CropType::class);
    }
}
