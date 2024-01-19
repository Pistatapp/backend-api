<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pump extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'name',
        'serial_number',
        'model',
        'manufacturer',
        'horsepower',
        'phase',
        'voltage',
        'ampere',
        'rpm',
        'pipe_size',
        'debi',
        'is_active',
        'is_healthy',
        'location',
    ];

    /**
     * Get the farm that owns the pump.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get valves of the pump.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function valves()
    {
        return $this->hasMany(Valve::class);
    }
}
