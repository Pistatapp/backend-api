<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class IrrigationValve extends Pivot
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'irrigation_id',
        'valve_id',
        'status',
        'opened_at',
        'closed_at',
        'duration',
    ];

    /**
     * Get attributes with default values
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'closed',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => 'string',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'duration' => 'integer',
        ];
    }
}
