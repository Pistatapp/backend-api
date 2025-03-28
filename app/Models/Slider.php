<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Slider extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'images',
        'page',
        'is_active',
        'interval',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected function casts()
    {
        return [
            'is_active' => 'boolean',
            'images' => 'array',
            'interval' => 'integer',
        ];
    }

    // Scope to filter only active sliders
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
