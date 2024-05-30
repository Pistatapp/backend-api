<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'timar_id',
        'timarable_id',
        'timarable_type',
    ];

    /**
     * Get all of the owning timarable models.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function timarable()
    {
        return $this->morphTo();
    }

    /**
     * Get the plan that owns the Feature
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the timar that owns the Feature
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function timar()
    {
        return $this->belongsTo(Timar::class);
    }
}
