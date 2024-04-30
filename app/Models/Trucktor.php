<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Trucktor extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'name',
        'start_work_time',
        'end_work_time',
        'expected_daily_work_time',
        'expected_monthly_work_time',
        'expected_yearly_work_time',
    ];

    /**
     * Get the farm that owns the Trucktor
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get driver of the trucktor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function driver()
    {
        return $this->hasOne(Driver::class);
    }

    /**
     * Get the gps device of the trucktor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function gpsDevice()
    {
        return $this->hasOne(GpsDevice::class);
    }

    /**
     * Get the gps reports for the trucktor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function gpsReports()
    {
        return $this->hasManyThrough(GpsReport::class, GpsDevice::class);
    }

    /**
     * Get the gps daily reports for the trucktor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gpsDailyReports()
    {
        return $this->hasMany(GpsDailyReport::class);
    }

    /**
     * Get the tasks for the trucktor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tasks()
    {
        return $this->hasMany(TrucktorTask::class);
    }

    /**
     * Get the reports for the trucktor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reports()
    {
        return $this->hasMany(TrucktorReport::class);
    }

    /**
     * Get the maintenance reports for the trucktor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function maintenanceReports()
    {
        return $this->morphMany(MaintenanceReport::class, 'maintainable');
    }
}
