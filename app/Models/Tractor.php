<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
        'is_in_repair_shop',
        'last_activity',
        'last_service_at',
        'last_service_notified_at',
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
            'is_in_repair_shop' => 'boolean',
            'last_activity' => 'datetime',
            'last_service_at' => 'datetime',
            'last_service_notified_at' => 'datetime',
        ];
    }

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_working' => false,
        'is_in_repair_shop' => false,
    ];

    /**
     * Get working window for the tractor.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function getWorkingWindow(Carbon $date): array
    {
        $startDateTime = $date->copy()->setTimeFromTimeString($this->start_work_time);
        $endDateTime = $date->copy()->setTimeFromTimeString($this->end_work_time);

        return [$startDateTime, $endDateTime];
    }

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
     * All task–target pivot rows across this tractor's tasks (polymorphic links to fields, plots, etc.).
     *
     * @return HasManyThrough<TractorTaskTaskable, TractorTask>
     */
    public function tractorTaskTaskables(): HasManyThrough
    {
        return $this->hasManyThrough(
            TractorTaskTaskable::class,
            TractorTask::class,
            'tractor_id',
            'tractor_task_id'
        );
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
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gpsData()
    {
        return $this->hasMany(GpsData::class);
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
