<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tractor extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'farm_id',
        'name',
        'start_work_time',
        'end_work_time',
        'expected_daily_work_time',
        'expected_monthly_work_time',
        'expected_yearly_work_time',
        'is_working',
        'last_activity',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_working' => 'boolean',
            'last_activity' => 'datetime',
        ];
    }

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_working' => false,
    ];

    /**
     * Scope a query to only include working tractors.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWorking($query)
    {
        return $query->where('is_working', true);
    }

    /**
     * Get the farm that owns the tractor
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get driver of the tractor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function driver()
    {
        return $this->hasOne(Driver::class);
    }

    /**
     * Get the gps device of the tractor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function gpsDevice()
    {
        return $this->hasOne(GpsDevice::class);
    }

    /**
     * Get the gps reports for the tractor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function gpsReports()
    {
        return $this->hasManyThrough(GpsReport::class, GpsDevice::class);
    }

    /**
     * Get the gps metrics calculations for the tractor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gpsMetricsCalculations()
    {
        return $this->hasMany(GpsMetricsCalculation::class);
    }

    /**
     * Get the tasks for the tractor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tasks()
    {
        return $this->hasMany(TractorTask::class);
    }

    /**
     * Get the reports for the tractor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reports()
    {
        return $this->hasMany(TractorReport::class);
    }

    /**
     * Get the gps data for the tractor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function gpsData()
    {
        return $this->hasManyThrough(GpsData::class, GpsDevice::class);
    }


    /**
     * Get the maintenance reports for the tractor.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function maintenanceReports()
    {
        return $this->morphMany(MaintenanceReport::class, 'maintainable');
    }

    /**
     * Scope a query to only include active tractors.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->whereHas('gpsDevice')->whereHas('driver');
    }
}
