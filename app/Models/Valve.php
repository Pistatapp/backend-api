<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Valve extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'plot_id',
        'name',
        'location',
        'is_open',
        'irrigation_area',
        'dripper_count',
        'dripper_flow_rate',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return  [
            'location' => 'array',
            'irrigation_area' => 'float',
            'dripper_count' => 'integer',
            'dripper_flow_rate' => 'float',
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

    /**
     * The irrigations that belong to the valve.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function irrigations()
    {
        return $this->belongsToMany(Irrigation::class);
    }

    /**
     * Get the plot that owns the valve.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function plot()
    {
        return $this->belongsTo(Plot::class);
    }
}
