<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class FarmUser extends Pivot
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'is_owner',
        'role',
    ];

    /**
     * The attributes that should have default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_owner' => false,
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts()
    {
        return [
            'is_owner' => 'boolean',
        ];
    }
}
