<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsReport extends Model
{
    use HasFactory;

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'is_stopped' => 'boolean',
        'is_starting_point' => 'boolean',
        'is_ending_point' => 'boolean',
        'date_time' => 'datetime',
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
