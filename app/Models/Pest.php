<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Pest extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'scientific_name',
        'description',
        'damage',
        'management',
        'standard_day_degree',
    ];

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
     * Get the phonology guide files for the pest.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function phonologyGuideFiles()
    {
        return $this->morphMany(PhonologyGuideFile::class, 'phonologyable');
    }
}
