<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Valve extends Model
{
    use HasFactory;

    protected $fillable = [
        'pump_id',
        'name',
        'location',
        'is_open',
        'flow_rate',
    ];

    protected $attributes = [
        'is_open' => false,
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return  [
            'flow_rate' => 'float',
            'location' => 'array',
            'is_open' => 'boolean',
        ];
    }

    /**
     * Get the pump that owns the valve.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function pump()
    {
        return $this->belongsTo(Pump::class);
    }
}
