<?php

namespace App\Models;

use App\Helpers\UniqueId;
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
        'unique_id',
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

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Valve $valve) {
            if (empty($valve->unique_id)) {
                $valve->fill(UniqueId::makeForTable('valves'));
            }
        });
    }
}
