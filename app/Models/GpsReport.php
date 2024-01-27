<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'imei',
        'latitude',
        'longitude',
        'speed',
        'status',
        'is_stopped',
        'stoppage_time',
        'is_starting_point',
        'is_ending_point',
    ];

    protected $casts = [
        'is_stopped' => 'boolean',
        'is_starting_point' => 'boolean',
        'is_ending_point' => 'boolean',
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

}
