<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = ['farm_id', 'name', 'supervisor_id'];

    /**
     * Get the farm that owns the team.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get all of the labors for the Team
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function labors()
    {
        return $this->hasMany(Labor::class);
    }

    /**
     * Get the supervisor associated with the Team
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function supervisor()
    {
        return $this->belongsTo(Labor::class, 'supervisor_id', 'id');
    }
}
