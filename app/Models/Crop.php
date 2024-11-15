<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Crop extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = ['name', 'cold_requirement'];

    /**
     * Get the farms for the crop.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function farms()
    {
        return $this->hasMany(Farm::class);
    }

    /**
     * Get the crop types for the crop.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function cropTypes()
    {
        return $this->hasMany(CropType::class);
    }
}
