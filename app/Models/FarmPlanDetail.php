<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FarmPlanDetail extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * Get all of the owning treatable models.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function treatable()
    {
        return $this->morphTo();
    }

    /**
     * Get the farm plan that owns the Feature
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function plan()
    {
        return $this->belongsTo(FarmPlan::class);
    }

    /**
     * Get the treatment that owns the Feature
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function treatment()
    {
        return $this->belongsTo(Treatment::class);
    }
}
