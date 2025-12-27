<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Irrigation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'labour_id',
        'farm_id',
        'pump_id',
        'start_time',
        'end_time',
        'created_by',
        'note',
        'status',
        'is_verified_by_admin',
    ];

    /**
     * Get attributes with default values
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'pending'
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts()
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
        ];
    }

    /**
     * The attributes that should be appended to the model.
     *
     * @var array<string>
     */
    protected $appends = [
        'duration',
    ];

    /**
     * Get the duration of the Irrigation
     *
     * @return string
     */
    public function getDurationAttribute()
    {
        return $this->start_time->diffInSeconds($this->end_time);
    }

    /**
     * Get the labour that owns the Irrigation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function labour()
    {
        return $this->belongsTo(Labour::class);
    }

    /**
     * Get the user that created the Irrigation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all of the valves for the Irrigation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function valves()
    {
        return $this->belongsToMany(Valve::class)
            ->using(IrrigationValve::class)
            ->withPivot('status', 'opened_at', 'closed_at', 'duration');
    }

    /**
     * Get the plots for the Irrigation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function plots()
    {
        return $this->belongsToMany(Plot::class);
    }

    /**
     * Get the farm that owns the Irrigation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the pump that owns the Irrigation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function pump()
    {
        return $this->belongsTo(Pump::class);
    }

    /**
     * Scope a query to only include irrigations of a given status
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return void
     */
    public function scopeFilter($query, string $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope a query to only include irrigations verified by admin
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param bool $isVerified
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVerifiedByAdmin($query, bool $isVerified)
    {
        $query->where('is_verified_by_admin', $isVerified);
    }
}
