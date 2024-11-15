<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'goal',
        'referrer',
        'counselors',
        'executors',
        'statistical_counselors',
        'implementation_location',
        'used_materials',
        'evaluation_criteria',
        'description',
        'start_date',
        'end_date',
        'status',
        'created_by',
        'farm_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * Get the farm that owns the plan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the user that created the plan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the features of the plan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function features()
    {
        return $this->hasMany(Feature::class);
    }

    /**
     * Get the plan's status.
     *
     * @return string
     */
    public function getStatusAttribute($value)
    {
        return ucfirst($value);
    }

    /**
     * Set the plan's status.
     *
     * @param string $value
     */
    public function setStatusAttribute($value)
    {
        $this->attributes['status'] = strtolower($value);
    }
}
