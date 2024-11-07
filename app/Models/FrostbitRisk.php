<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrostbitRisk extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['type', 'notify'];

    /**
     * Get the farm that owns the frostbit risk.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}
