<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NutrientSample extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'nutrient_diagnosis_request_id',
        'field_id',
        'field_area',
        'load_amount',
        'nitrogen',
        'phosphorus',
        'potassium',
        'calcium',
        'magnesium',
        'iron',
        'copper',
        'zinc',
        'boron',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_area' => 'float',
            'load_amount' => 'float',
            'nitrogen' => 'float',
            'phosphorus' => 'float',
            'potassium' => 'float',
            'calcium' => 'float',
            'magnesium' => 'float',
            'iron' => 'float',
            'copper' => 'float',
            'zinc' => 'float',
            'boron' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the nutrient diagnosis request that owns the sample.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(NutrientDiagnosisRequest::class, 'nutrient_diagnosis_request_id');
    }

    /**
     * Get the field that owns the sample.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class);
    }
}
