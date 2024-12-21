<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Labour extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'farm_id',
        'type',
        'fname',
        'lname',
        'national_id',
        'mobile',
        'position',
        'project_start_date',
        'project_end_date',
        'work_type',
        'work_days',
        'work_hours',
        'start_work_time',
        'end_work_time',
        'salary',
        'daily_salary',
        'monthly_salary',
        'is_working',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string|mixed>
     */
    protected function casts(): array
    {
        return [
            'is_working' => 'boolean',
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
     * Scope a query to only include working labours.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWorking($query)
    {
        return $query->where('is_working', true);
    }

    /**
     * Get full name of the Labour
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        return "{$this->fname} {$this->lname}";
    }

    /**
     * Get the teams that owns the Labor
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function teams()
    {
        return $this->belongsToMany(Team::class);
    }

    /**
     * Get the farm that owns the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}
