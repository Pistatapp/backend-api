<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Slider extends Model
{
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
}
