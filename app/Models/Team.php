<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = ['farm_id', 'name'];

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
}
