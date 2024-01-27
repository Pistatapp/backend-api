<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'trucktor_id',
        'name',
        'imei',
        'sim_number',
    ];

    /**
     * Get the user that owns the GpsDevice
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the trucktor that owns the GpsDevice
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function trucktor()
    {
        return $this->belongsTo(Trucktor::class);
    }

    /**
     * Get the gps reports for the gps device.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gpsReports()
    {
        return $this->hasMany(GpsReport::class);
    }
}
