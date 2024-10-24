<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CropType extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = ['name', 'standard_day_degree'];

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'standard_day_degree' => 22.5,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'standard_day_degree' => 'float',
        ];
    }

    /**
     * Get the crop that owns the crop type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function crop()
    {
        return $this->belongsTo(Crop::class);
    }

    /**
     * Get the fields for the crop type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function fields()
    {
        return $this->hasMany(Field::class);
    }

    /**
     * Get the phonology files for the crop type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function phonologyGuideFiles()
    {
        return $this->morphMany(PhonologyGuideFile::class, 'phonologyable');
    }

    /**
     * Get the load prediction table for the crop type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function loadPredictionTable()
    {
        return $this->hasOne(LoadPredictionTable::class);
    }
}
