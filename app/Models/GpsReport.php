<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsReport extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * The timestamps are disabled.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_stopped' => 'boolean',
            'is_starting_point' => 'boolean',
            'is_ending_point' => 'boolean',
            'date_time' => 'datetime',
            'coordinate' => 'array',
            'directions' => 'array',
            'is_off' => 'boolean',
        ];
    }

    /**
     * The default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_stopped' => false,
        'is_starting_point' => false,
        'is_ending_point' => false,
        'stoppage_time' => 0,
        'directions' => '{"ew":0,"ns":0}',
        'is_off' => false,
    ];

    /**
     * Get the gpsDevice that owns the GpsReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gpsDevice()
    {
        return $this->belongsTo(GpsDevice::class);
    }

    /**
     * Get the EW direction from the directions array
     *
     * @return int
     */
    public function getEwDirectionAttribute(): int
    {
        return $this->directions['ew'] ?? 0;
    }

    /**
     * Get the NS direction from the directions array
     *
     * @return int
     */
    public function getNsDirectionAttribute(): int
    {
        return $this->directions['ns'] ?? 0;
    }
}
