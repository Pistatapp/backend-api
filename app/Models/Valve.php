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
        'pump_id',
        'name',
        'location',
        'flow_rate',
        'field_id',
        'is_open',
        'irrigated_area',
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
            'irrigated_area' => 'float',
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
     * Get the field that owns the valve.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function field()
    {
        return $this->belongsTo(Field::class);
    }
}
