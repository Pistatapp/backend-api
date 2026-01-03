<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsDevice extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'tractor_id',
        'name',
        'imei',
        'sim_number',
        'device_type',
        'device_fingerprint',
        'mobile_number',
        'farm_id',
        'labour_id',
        'is_active',
        'approved_at',
        'approved_by',
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
     * Get the tractor that owns the GpsDevice
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tractor()
    {
        return $this->belongsTo(Tractor::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Get the gps data for the gps device.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function data()
    {
        return $this->hasMany(GpsData::class);
    }

    /**
     * Get the labour that owns the GpsDevice
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function labour()
    {
        return $this->belongsTo(Labour::class);
    }

    /**
     * Get the farm that owns the GpsDevice
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the user who approved the device
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope a query to only include worker devices.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWorkerDevices($query)
    {
        return $query->whereIn('device_type', ['mobile_phone', 'personal_gps']);
    }

    /**
     * Scope a query to only include tractor devices.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTractorDevices($query)
    {
        return $query->whereNotNull('tractor_id');
    }

    /**
     * Scope a query to only include active devices.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include unassigned devices.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnassigned($query)
    {
        return $query->whereNull('labour_id')->whereNull('tractor_id');
    }

    /**
     * Scope a query to only include devices for a specific farm.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $farmId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }
}
