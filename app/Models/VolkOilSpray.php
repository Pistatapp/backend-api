<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VolkOilSpray extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'farm_id',
        'start_dt',
        'end_dt',
        'min_temp',
        'max_temp',
        'cold_requirement',
        'created_by'
    ];

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'min_temp' => 0,
        'max_temp' => 7,
    ];

    /**
     * Get the farm that owns the cold requirement notification.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Scope a query to only include notifications for the specific farm.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFor($query, Farm $farm)
    {
        return $query->where('farm_id', $farm->id);
    }

    /**
     * Get the user that created the notification.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
